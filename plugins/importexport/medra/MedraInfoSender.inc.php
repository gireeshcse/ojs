<?php

/**
 * @file plugins/importexport/medra/MedraInfoSender.php
 *
 * Copyright (c) 2013-2016 Simon Fraser University Library
 * Copyright (c) 2003-2016 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class MedraInfoSender
 * @ingroup plugins_importexport_medra
 *
 * @brief Scheduled task to send deposits to mEDRA.
 */

import('lib.pkp.classes.scheduledTask.ScheduledTask');


class MedraInfoSender extends ScheduledTask {
	/** @var $_plugin MedraExportPlugin */
	var $_plugin;

	/**
	 * Constructor.
	 * @param $argv array task arguments
	 */
	function MedraInfoSender($args) {
		PluginRegistry::loadCategory('importexport');
		$plugin = PluginRegistry::getPlugin('importexport', 'MedraExportPlugin'); /* @var $plugin MedraExportPlugin */
		$this->_plugin = $plugin;

		if (is_a($plugin, 'MedraExportPlugin')) {
			$plugin->addLocaleData();
		}

		parent::ScheduledTask($args);
	}

	/**
	 * @copydoc ScheduledTask::getName()
	 */
	function getName() {
		return __('plugins.importexport.medra.senderTask.name');
	}

	/**
	 * @copydoc ScheduledTask::executeActions()
	 */
	function executeActions() {
		if (!$this->_plugin) return false;

		$plugin = $this->_plugin;
		$journals = $this->_getJournals();

		foreach ($journals as $journal) {
			$pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $journal->getId());
			$doiPubIdPlugin = $pubIdPlugins['doipubidplugin'];

			if ($doiPubIdPlugin->getSetting($journal->getId(), 'enableIssueDoi')) {
				// Get unregistered issues
				$unregisteredIssues = $plugin->getUnregisteredIssues($journal);
				// If there are issues to be deposited
				if (count($unregisteredIssues)) {
					$this->_registerObjects($unregisteredIssues, 'issue=>medra-xml', $journal, 'issues');
				}
			}

			if ($doiPubIdPlugin->getSetting($journal->getId(), 'enableSubmissionDoi')) {
				// Get unregistered articles
				$unregisteredArticles = $plugin->getUnregisteredArticles($journal);
				// If there are articles to be deposited
				if (count($unregisteredArticles)) {
					$this->_registerObjects($unregisteredArticles, 'article=>medra-xml', $journal, 'articles');
				}
			}

			if ($doiPubIdPlugin->getSetting($journal->getId(), 'enableRepresentationDoi')) {
				// Get unregistered galleys
				$unregisteredGalleys = $plugin->getUnregisteredGalleys($journal);
				// If there are galleys to be deposited
				if (count($unregisteredGalleys)) {
					$this->_registerObjects($unregisteredGalleys, 'galley=>medra-xml', $journal, 'galleys');
				}
			}
		}
		return true;
	}

	/**
	 * Get all journals that meet the requirements to have
	 * their DOIs sent to mEDRA.
	 * @return array
	 */
	function _getJournals() {
		$plugin = $this->_plugin;
		$contextDao = Application::getContextDAO(); /* @var $contextDao JournalDAO */
		$journalFactory = $contextDao->getAll(true);

		$journals = array();
		while($journal = $journalFactory->next()) {
			$journalId = $journal->getId();
			if (!$plugin->getSetting($journalId, 'username') || !$plugin->getSetting($journalId, 'password') || !$plugin->getSetting($journalId, 'automaticRegistration')) continue;

			$doiPrefix = null;
			$pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $journalId);
			if (isset($pubIdPlugins['doipubidplugin'])) {
				$doiPubIdPlugin = $pubIdPlugins['doipubidplugin'];
				if (!$doiPubIdPlugin->getSetting($journalId, 'enabled')) continue;
				$doiPrefix = $doiPubIdPlugin->getSetting($journalId, 'doiPrefix');
			}

			if ($doiPrefix) {
				$journals[] = $journal;
			} else {
				$this->addExecutionLogEntry(__('plugins.importexport.common.senderTask.warning.noDOIprefix', array('path' => $journal->getPath())), SCHEDULED_TASK_MESSAGE_TYPE_WARNING);
			}
		}
		return $journals;
	}


	/**
	 * Register objects
	 * @param $objects array
	 * @param $filter string
	 * @param $journal Journal
	 * @param $objectsFileNamePart string
	 */
	function _registerObjects($objects, $filter, $journal, $objectsFileNamePart) {
		$plugin = $this->_plugin;
		// export XML
		$exportXml = $plugin->exportXML($objects, $filter, $journal);
		// Write the XML to a file.
		$exportFileName = $plugin->getExportFileName($journal, $objectsFileNamePart);
		file_put_contents($exportFileName, $exportXml);
		// Deposit the XML file.
		$result = $plugin->depositXML($objects, $journal, $exportFileName);
		if ($result !== true) {
			$this->_addLogEntry($result);
		}
		// Remove all temporary files.
		$plugin->cleanTmpfile($exportFileName);
	}

	/**
	 * Add execution log entry
	 * @param $result array
	 */
	function _addLogEntry($result) {
		if (is_array($result)) {
			foreach($result as $error) {
				assert(is_array($error) && count($error) >= 1);
				$this->addExecutionLogEntry(
					__($error[0], array('param' => (isset($error[1]) ? $error[1] : null))),
					SCHEDULED_TASK_MESSAGE_TYPE_WARNING
				);
			}
		} else {
			$this->addExecutionLogEntry(
				__('plugins.importexport.common.register.error.mdsError', array('param' => ' - ')),
				SCHEDULED_TASK_MESSAGE_TYPE_WARNING
			);
		}
	}

}
?>
