<?php

/**
 * @file plugins/generic/funding/FundingPlugin.inc.php
 *
 * Copyright (c) 2014-2017 Simon Fraser University
 * Copyright (c) 2003-2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class FundingPlugin
 * @ingroup plugins_generic_funding

 * @brief Add funding data to the submission metadata, consider them in the Crossref export,
 * and display them on the submission view page.
 *
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class FundingPlugin extends GenericPlugin {

	/**
	 * @copydoc Plugin::getName()
	 */
	function getName() {
		return 'FundingPlugin';
    }

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
    function getDisplayName() {
		return __('plugins.generic.funding.displayName');
    }

	/**
	 * @copydoc Plugin::getDescription()
	 */
    function getDescription() {
		return __('plugins.generic.funding.description');
    }

	/**
	 * Register the plugin, if enabled.
	 * @param $category string
	 * @param $path string
	 * @return boolean
	 */
    function register($category, $path) {
		$success = parent::register($category, $path);
		if ($success && $this->getEnabled()) {
			import('plugins.generic.funding.classes.FunderDAO');
			$funderDao = new FunderDAO();
			DAORegistry::registerDAO('FunderDAO', $funderDao);

			HookRegistry::register('Templates::Submission::SubmissionMetadataForm::AdditionalMetadata', array($this, 'metadataFieldEdit'));

			HookRegistry::register('LoadComponentHandler', array($this, 'setupGridHandler'));

			HookRegistry::register('TemplateManager::display',array($this, 'addGridhandlerJs'));

			HookRegistry::register('Templates::Article::Details', array($this, 'addSubmissionDisplay'));
			HookRegistry::register('Templates::Catalog::Book::Details', array($this, 'addSubmissionDisplay'));

			HookRegistry::register('articlecrossrefxmlfilter::execute', array($this, 'addCrossrefElement'));
        }
		return $success;
	}


	/**
	 * Permit requests to the Funder grid handler
	 * @param $hookName string The name of the hook being invoked
	 * @param $args array The parameters to the invoked hook
	 */
	function setupGridHandler($hookName, $params) {
		$component =& $params[0];
		if ($component == 'plugins.generic.funding.controllers.grid.FunderGridHandler') {
			import($component);
			FunderGridHandler::setPlugin($this);
			return true;
		}
		return false;
	}

	/**
	 * Insert funder grid in the submission metadata form
	 */
	function metadataFieldEdit($hookName, $params) {
		$smarty =& $params[1];
		$output =& $params[2];
		$request = $this->getRequest();
		$output .= $smarty->fetch($this->getTemplatePath() . 'metadataForm.tpl');
		return false;
	}

	/**
	 * Add custom gridhandlerJS for backend
	 */
	function addGridhandlerJs($hookName, $params) {
		$templateMgr = $params[0];
		$request = $this->getRequest();
		$gridHandlerJs = $this->getJavaScriptURL($request, false) . DIRECTORY_SEPARATOR . 'FunderGridHandler.js';
		$templateMgr->addJavaScript(
			'FunderGridHandlerJs',
			$gridHandlerJs,
			array('contexts' => 'backend')
		);
		return false;
	}

	/**
	* Hook to Templates::Article::Details and Templates::Catalog::Book::Details and list funder information
	* @param $hookName string
	* @param $params array
	*/
	function addSubmissionDisplay($hookName, $params) {
		$templateMgr = $params[1];
		$output =& $params[2];

		$submission = isset($templateMgr->get_template_vars('monograph')) ? $templateMgr->get_template_vars('monograph') : $templateMgr->get_template_vars('article');

		$funderDao = DAORegistry::getDAO('FunderDAO');
		$funders = $funderDao->getBySubmissionId($submission->getId());
		$funders = $funders->toArray();

		if ($funders){
			$templateMgr->assign('funders', $funders);
			$output .= $templateMgr->fetch($this->getTemplatePath() . 'listFunders.tpl');
		}

		return false;

	}

	/**
	 * Hook to articlecrossrefxmlfilter::execute and add funding data to the Crossref XML export
	 * @param $hookName string
	 * @param $params array
	 */
	function addCrossrefElement($hookName, $params) {
		$preliminaryOutput =& $params[0];
		$request = Application::getRequest();
		$context = $request->getContext();
		$publishedArticleDAO = DAORegistry::getDAO('PublishedArticleDAO');
		$funderDAO = DAORegistry::getDAO('FunderDAO');

		$crossrefFRNS = 'http://www.crossref.org/fundref.xsd';
		$rootNode=$preliminaryOutput->documentElement;
		$rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:fr', $crossrefFRNS);
		$articleNodes = $preliminaryOutput->getElementsByTagName('journal_article');
		foreach ($articleNodes as $articleNode) {
			$doiDataNode = $articleNode->getElementsByTagName('doi_data')->item(0);
			$aiProgramDataNode = $articleNode->getElementsByTagNameNS('http://www.crossref.org/AccessIndicators.xsd', 'program')->item(0);
			$doiNode = $doiDataNode->getElementsByTagName('doi')->item(0);
			$doi = $doiNode->nodeValue;

			$programNode = $preliminaryOutput->createElementNS($crossrefFRNS, 'fr:program');
			$programNode->setAttribute('name', 'fundref');

			$publishedArticle = $publishedArticleDAO->getPublishedArticleByPubId('doi', $doi, $context->getId());
			assert($publishedArticle);
			$funders = $funderDAO->getBySubmissionId($publishedArticle->getId());
			while ($funder = $funders->next()) {
				$groupNode = $preliminaryOutput->createElementNS($crossrefFRNS, 'fr:assertion');
				$groupNode->setAttribute('name', 'fundgroup');
				$funderNameNode = $preliminaryOutput->createElementNS($crossrefFRNS, 'fr:assertion', $funder->getFunderName());
				$funderNameNode->setAttribute('name', 'funder_name');
				$funderIdNode = $preliminaryOutput->createElementNS($crossrefFRNS, 'fr:assertion', $funder->getFunderIdentification());
				$funderIdNode->setAttribute('name', 'funder_identifier');
				$funderNameNode->appendChild($funderIdNode);
				$groupNode->appendChild($funderNameNode);
				$funderGrantsString = $funder->getFunderGrants();
				if (!empty($funderGrantsString)) {
					$funderGrants = explode(';', $funderGrantsString);
					foreach ($funderGrants as $funderGrant) {
						$awardNumberNode = $preliminaryOutput->createElementNS($crossrefFRNS, 'fr:assertion', $funderGrant);
						$awardNumberNode->setAttribute('name', 'award_number');
						$groupNode->appendChild($awardNumberNode);
					}
				}
				$programNode->appendChild($groupNode);
			}
			if ($aiProgramDataNode) {
				$articleNode->insertBefore($programNode, $aiProgramDataNode);
			} else {
				$articleNode->insertBefore($programNode, $doiDataNode);
			}
		}
		return false;
	}

	/**
	 * @copydoc Plugin::getTemplatePath()
	 */
	function getTemplatePath($inCore = false) {
		return parent::getTemplatePath($inCore) . 'templates/';
	}

	/**
	 * @copydoc Plugin::getInstallSchemaFile()
	 */
	function getInstallSchemaFile() {
		return $this->getPluginPath() . '/schema.xml';
	}

	/**
	 * Get the JavaScript URL for this plugin.
	 */
	function getJavaScriptURL() {
		return Request::getBaseUrl() . DIRECTORY_SEPARATOR . $this->getPluginPath() . DIRECTORY_SEPARATOR . 'js';
	}

}

?>