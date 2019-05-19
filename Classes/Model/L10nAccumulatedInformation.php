<?php

namespace Localizationteam\L10nmgr\Model;

/***************************************************************
 * Copyright notice
 * (c) 2006 Kasper Skårhøj <kasperYYYY@typo3.com>
 * All rights reserved
 * This script is part of the TYPO3 project. The TYPO3 project is
 * free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * The GNU General Public License can be found at
 * http://www.gnu.org/copyleft/gpl.html.
 * This script is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Localizationteam\L10nmgr\Constants;
use Localizationteam\L10nmgr\LanguageRestriction\Collection\LanguageRestrictionCollection;
use Localizationteam\L10nmgr\Model\Tools\Tools;
use TYPO3\CMS\Backend\Tree\View\PageTreeView;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * l10nAccumulatedInformations
 * calculates accumulated informations for a l10n.
 *Needs a tree object and a l10ncfg to work.
 * This object is a value object (means it has no identity and can therefore be created and deleted “everywhere”).
 * However this object should be generated by the relevant factory method in the l10nconfiguration object.
 * This object represents the relevant records which belongs to a l10ncfg in the concrete pagetree!
 * The main method is the getInfoArrayForLanguage() which returns the $accum Array with the accumulated informations.
 *
 * @package TYPO3
 * @subpackage tx_l10nmgr
 */
class L10nAccumulatedInformation
{
    /**
     * @var string The status of this object, set to processed if internal variables are calculated.
     */
    var $objectStatus = 'new';
    /**
     * @var PageTreeView
     */
    var $tree;
    /**
     * @var array Selected l10nmgr configuration
     */
    var $l10ncfg = [];
    /**
     * @var array List of not allowed doktypes
     */
    var $disallowDoktypes = ['--div--', '255'];
    /**
     * @var int sys_language_uid of target language
     */
    var $sysLang;
    /**
     * @var int sys_language_uid of forced source language
     */
    var $forcedPreviewLanguage;
    /**
     * @var array Information about collected data for translation
     */
    var $_accumulatedInformations = [];
    /**
     * @var int Field count, might be needed by tranlation agencies
     */
    var $_fieldCount = 0;
    /**
     * @var int Word count, might be needed by tranlation agencies
     */
    var $_wordCount = 0;
    /**
     * @var array Extension's configuration as from the EM
     */
    protected $extensionConfiguration = [];
    /**
     * @var array Index of pages to be excluded from translation
     */
    protected $excludeIndex = [];
    /**
     * @var array Index of pages to be included with translation
     */
    protected $includeIndex = [];

    /**
     * Constructor
     *
     * @param $tree
     * @param $l10ncfg
     * @param $sysLang
     */
    public function __construct($tree, $l10ncfg, $sysLang)
    {
        // Load the extension's configuration
        $this->extensionConfiguration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['l10nmgr']);
        $this->disallowDoktypes = GeneralUtility::trimExplode(',', $this->extensionConfiguration['disallowDoktypes']);
        $this->tree = $tree;
        $this->l10ncfg = $l10ncfg;
        $this->sysLang = $sysLang;
    }

    /**
     * @param int $prevLangId
     */
    public function setForcedPreviewLanguage($prevLangId)
    {
        $this->forcedPreviewLanguage = $prevLangId;
    }

    /**
     * return information array with accumulated information. This way client classes have access to the accumulated array directly. and can read this array in order to create some output...
     *
     * @return array Complete Information array
     */
    public function getInfoArray()
    {
        $this->process();
        return $this->_accumulatedInformations;
    }

    /**
     * @return void
     */
    protected function process()
    {
        if ($this->objectStatus != 'processed') {
            $this->_calculateInternalAccumulatedInformationsArray();
        }
        $this->objectStatus = 'processed';
    }

    /** set internal _accumulatedInformations array. Is called from constructor and uses the given tree, lang and l10ncfg
     *
     * @return void
     **/
    protected function _calculateInternalAccumulatedInformationsArray()
    {
        global $TCA;
        $tree = $this->tree;
        $l10ncfg = $this->l10ncfg;
        $accum = [];
        $sysLang = $this->sysLang;
        // FlexForm Diff data:
        $flexFormDiff = unserialize($l10ncfg['flexformdiff']);
        $flexFormDiff = $flexFormDiff[$sysLang];
        $this->excludeIndex = array_flip(GeneralUtility::trimExplode(',', $l10ncfg['exclude'], true));
        $tableUidConstraintIndex = array_flip(GeneralUtility::trimExplode(',', $l10ncfg['tableUidConstraint'], 1));
        // Init:
        /** @var Tools $t8Tools */
        $t8Tools = GeneralUtility::makeInstance(Tools::class);
        $t8Tools->verbose = false; // Otherwise it will show records which has fields but none editable.
        if ($l10ncfg['incfcewithdefaultlanguage'] == 1) {
            $t8Tools->includeFceWithDefaultLanguage = true;
        }
        // Set preview language (only first one in list is supported):
        if ($this->forcedPreviewLanguage != '') {
            $previewLanguage = $this->forcedPreviewLanguage;
        } else {
            $previewLanguage = current(GeneralUtility::intExplode(',',
                $this->getBackendUser()->getTSConfigVal('options.additionalPreviewLanguages')));
        }
        if ($previewLanguage) {
            $t8Tools->previewLanguages = [$previewLanguage];
        }
        $fileList = '';
        // Traverse tree elements:
        /**
         * @var $rootlineUtility RootlineUtility
         */
        foreach ($tree->tree as $treeElement) {
            $pageId = $treeElement['row']['uid'];
            if ($treeElement['row']['l10nmgr_configuration'] === Constants::L10NMGR_CONFIGURATION_DEFAULT) {
                $rootlineUtility = GeneralUtility::makeInstance(RootlineUtility::class, $pageId);
                $rootline = $rootlineUtility->get();
                if (!empty($rootline)) {
                    foreach ($rootline as $rootlinePage) {
                        if ($rootlinePage['l10nmgr_configuration_next_level'] === Constants::L10NMGR_CONFIGURATION_DEFAULT) {
                            continue;
                        } elseif ($rootlinePage['l10nmgr_configuration_next_level'] === Constants::L10NMGR_CONFIGURATION_NONE || $rootlinePage['l10nmgr_configuration_next_level'] === Constants::L10NMGR_CONFIGURATION_INCLUDE) {
                            break;
                        } elseif ($rootlinePage['l10nmgr_configuration_next_level'] === Constants::L10NMGR_CONFIGURATION_EXCLUDE) {
                            $this->excludeIndex['pages:' . $pageId] = 1;
                            break;
                        }
                    }
                }
            } elseif ($treeElement['row']['l10nmgr_configuration'] === Constants::L10NMGR_CONFIGURATION_EXCLUDE) {
                $this->excludeIndex['pages:' . $pageId] = 1;
            }
            if (!empty($treeElement['row'][Constants::L10NMGR_LANGUAGE_RESTRICTION_FIELDNAME])) {
                $languageIsRestricted = LanguageRestrictionCollection::load(
                    (int)$sysLang,
                    true,
                    'pages',
                    Constants::L10NMGR_LANGUAGE_RESTRICTION_FIELDNAME
                );
                if (count($languageIsRestricted) > 0) {
                    $this->excludeIndex['pages:' . $pageId] = 1;
                }
            }
            if (!isset($this->excludeIndex['pages:' . $pageId]) && !in_array($treeElement['row']['doktype'],
                    $this->disallowDoktypes)
            ) {
                $accum[$pageId]['header']['title'] = $treeElement['row']['title'];
                $accum[$pageId]['header']['icon'] = $treeElement['HTML'];
                $accum[$pageId]['header']['prevLang'] = $previewLanguage;
                $accum[$pageId]['items'] = [];
                // Traverse tables:
                foreach ($TCA as $table => $cfg) {
                    // Only those tables we want to work on:
                    if (GeneralUtility::inList($l10ncfg['tablelist'], $table)) {
                        if ($table === 'pages') {
                            $accum[$pageId]['items'][$table][$pageId] = $t8Tools->translationDetails('pages',
                                BackendUtility::getRecordWSOL('pages', $pageId), $sysLang, $flexFormDiff,
                                $previewLanguage);
                            $this->_increaseInternalCounters($accum[$pageId]['items'][$table][$pageId]['fields']);
                        } else {
                            $allRows = $t8Tools->getRecordsToTranslateFromTable($table, $pageId);
                            if (is_array($allRows)) {
                                if (count($allRows)) {
                                    // Now, for each record, look for localization:
                                    foreach ($allRows as $row) {
                                        if (!empty($row[Constants::L10NMGR_LANGUAGE_RESTRICTION_FIELDNAME])) {
                                            $languageIsRestricted = LanguageRestrictionCollection::load(
                                                (int)$sysLang,
                                                true,
                                                $table,
                                                Constants::L10NMGR_LANGUAGE_RESTRICTION_FIELDNAME
                                            );
                                            if (count($languageIsRestricted) > 0) {
                                                $this->excludeIndex[$table . ':' . (int)$row['uid']] = 1;
                                                continue;
                                            }
                                        }
                                        BackendUtility::workspaceOL($table, $row);
                                        if ($table === 'sys_file_reference') {
                                            $fileList .= $fileList ? ',' . (int)$row['uid_local'] : (int)$row['uid_local'];
                                        }
                                        if (is_array($row) && count($tableUidConstraintIndex) > 0) {
                                            if (is_array($row) && isset($tableUidConstraintIndex[$table . ':' . $row['uid']])) {
                                                $accum[$pageId]['items'][$table][$row['uid']] = $t8Tools->translationDetails($table,
                                                    $row, $sysLang, $flexFormDiff, $previewLanguage);
                                                $this->_increaseInternalCounters($accum[$pageId]['items'][$table][$row['uid']]['fields']);
                                            }
                                        } else {
                                            if (is_array($row) && !isset($this->excludeIndex[$table . ':' . $row['uid']])) {
                                                $accum[$pageId]['items'][$table][$row['uid']] = $t8Tools->translationDetails($table,
                                                    $row, $sysLang, $flexFormDiff, $previewLanguage);
                                                $this->_increaseInternalCounters($accum[$pageId]['items'][$table][$row['uid']]['fields']);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if ($table === 'sys_file_reference' && !empty($fileList)) {
                        $fileList = implode(',',
                            array_keys(array_flip(GeneralUtility::intExplode(',', $fileList, true))));
                        if (!empty($fileList)) {
                            $metaData = $this->getDatabaseConnection()->exec_SELECTgetRows('uid', 'sys_file_metadata',
                                'sys_language_uid = ' . (int)$previewLanguage . ' AND file IN (' . $fileList . ')', '',
                                'uid', '', 'uid');
                            if (!empty($metaData)) {
                                $l10ncfg['include'] .= $l10ncfg['include'] ? ',' : '';
                                $l10ncfg['include'] .= 'sys_file_metadata:' . implode(',sys_file_metadata:',
                                        array_keys($metaData));
                            }
                        }
                    }
                }
            }
        }

        $this->addPagesMarkedAsIncluded($l10ncfg['include'], $l10ncfg['exclude']);
        foreach ($this->includeIndex as $recId => $rec) {
            list($table, $uid) = explode(':', $recId);
            $row = BackendUtility::getRecordWSOL($table, $uid);
            if (count($row)) {
                $accum[-1]['items'][$table][$row['uid']] = $t8Tools->translationDetails($table, $row, $sysLang,
                    $flexFormDiff, $previewLanguage);
                $this->_increaseInternalCounters($accum[-1]['items'][$table][$row['uid']]['fields']);
            }
        }
        // debug($accum);
        $this->_accumulatedInformations = $accum;
    }

    /**
     * Returns the Backend User
     * @return BackendUserAuthentication
     */
    protected function getBackendUser()
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * @param array $fieldsArray
     */
    protected function _increaseInternalCounters($fieldsArray)
    {
        if (is_array($fieldsArray)) {
            $this->_fieldCount = $this->_fieldCount + count($fieldsArray);
            if (function_exists('str_word_count')) {
                foreach ($fieldsArray as $v) {
                    $this->_wordCount = $this->_wordCount + str_word_count($v['defaultValue']);
                }
            }
        }
    }

    /**
     * Get DatabaseConnection instance - $GLOBALS['TYPO3_DB']
     *
     * This method should be used instead of direct access to
     * $GLOBALS['TYPO3_DB'] for easy IDE auto completion.
     *
     * @return DatabaseConnection
     * @deprecated since TYPO3 v8, will be removed in TYPO3 v9
     */
    protected function getDatabaseConnection()
    {
        GeneralUtility::logDeprecatedFunction();
        return $GLOBALS['TYPO3_DB'];
    }

    /**
     * @param string $indexList
     */
    protected function addPagesMarkedAsIncluded($indexList, $excludeList)
    {
        $this->includeIndex = [];
        $this->excludeIndex = array_flip(GeneralUtility::trimExplode(',', $excludeList, true));
        if ($indexList) {
            $this->includeIndex = array_flip(GeneralUtility::trimExplode(',', $indexList, true));
        }
        $enableClause = BackendUtility::BEenableFields('pages');
        $explicitlyIncludedPages = $this->getDatabaseConnection()->exec_SELECTgetRows('uid', 'pages',
            'l10nmgr_configuration = ' . Constants::L10NMGR_CONFIGURATION_INCLUDE . $enableClause);
        if (!empty($explicitlyIncludedPages)) {
            foreach ($explicitlyIncludedPages as $page) {
                if (!isset($this->excludeIndex['pages:' . $page['uid']]) && !in_array($page['doktype'],
                        $this->disallowDoktypes)
                ) {
                    $this->includeIndex['pages:' . $page['uid']] = 1;
                }
            }
        }
        $includingParentPages = $this->getDatabaseConnection()->exec_SELECTgetRows('uid', 'pages',
            'l10nmgr_configuration_next_level = ' . Constants::L10NMGR_CONFIGURATION_INCLUDE . $enableClause);
        if (!empty($includingParentPages)) {
            foreach ($includingParentPages as $parentPage) {
                $this->addSubPagesRecursively($parentPage['uid']);
            }
        }
    }

    /**
     * Walks through a tree branch and checks if pages are to be included
     * Will ignore pages with explicit l10nmgr_configuration settings but still walk through their subpages
     * @param int $uid
     * @param int $level
     */
    protected function addSubPagesRecursively($uid, $level = 0)
    {
        $level++;
        if ($uid > 0 && $level < 100) {
            $enableClause = BackendUtility::BEenableFields('pages');
            $subPages = $this->getDatabaseConnection()->exec_SELECTgetRows('uid,pid,l10nmgr_configuration,l10nmgr_configuration_next_level',
                'pages',
                'pid = ' . (int)$uid . $enableClause);
            if (!empty($subPages)) {
                foreach ($subPages as $page) {
                    if ($page['l10nmgr_configuration'] === Constants::L10NMGR_CONFIGURATION_DEFAULT) {
                        $this->includeIndex['pages:' . $page['uid']] = 1;
                    }
                    if ($page['l10nmgr_configuration_next_level'] === Constants::L10NMGR_CONFIGURATION_DEFAULT || $page['l10nmgr_configuration_next_level'] === Constants::L10NMGR_CONFIGURATION_INCLUDE) {
                        $this->addSubPagesRecursively($page['uid'], $level);
                    }
                }
            }
        }
    }

    /**
     * @return int
     */
    public function getFieldCount()
    {
        return $this->_fieldCount;
    }

    /**
     * @return int
     */
    public function getWordCount()
    {
        return $this->_wordCount;
    }
}