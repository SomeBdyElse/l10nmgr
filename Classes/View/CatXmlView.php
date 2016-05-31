<?php
namespace Localizationteam\L10nmgr\View;

/***************************************************************
 *  Copyright notice
 *  (c) 2006 Kasper Skårhøj <kasperYYYY@typo3.com>
 *  All rights reserved
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use DOMDocument;
use DOMElement;
use DOMNode;
use Localizationteam\L10nmgr\Model\Tools\DOMTools;
use Localizationteam\L10nmgr\Model\Tools\Utf8Tools;
use Localizationteam\L10nmgr\Model\Tools\XmlTools;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * CATXMLView: Renders the XML for the use for translation agencies
 *
 * @author  Kasper Skaarhoj <kasperYYYY@typo3.com>
 * @author  Daniel Poetzinger <development@aoemedia.de>
 * @author  Daniel Zielinski <d.zielinski@L10Ntech.de>
 * @author  Fabian Seltmann <fs@marketing-factory.de>
 * @author  Andreas Otto <andreas.otto@dkd.de>
 * @package TYPO3
 * @subpackage tx_l10nmgr
 */
class CatXmlView extends AbstractExportView
{
    /**
     * @var DOMTools
     */
    protected $domTool;

    /**
     * @var  integer $forcedSourceLanguage Overwrite the default language uid with the desired language to export
     */
    var $forcedSourceLanguage = false;

    var $exportType = '1';

    /**
     * @var string
     */
    protected $targetIso;

    /**
     * @var DOMDocument
     */
    protected $domDocument;


    function __construct($l10ncfgObj, $sysLang)
    {
        $this->domTool = GeneralUtility::makeInstance(DOMTools::class);
        parent::__construct($l10ncfgObj, $sysLang);
    }

    /**
     * Render the simple XML export
     *
     * @return string filename
     */
    function render()
    {
        $this->domDocument = new DOMDocument();

        $sysLang = $this->sysLang;

        /** @var  $accumObj */
        $accumObj = $this->l10ncfgObj->getL10nAccumulatedInformationsObjectForLanguage($sysLang);
        if ($this->forcedSourceLanguage) {
            $accumObj->setForcedPreviewLanguage($this->forcedSourceLanguage);
        }
        $accum = $accumObj->getInfoArray();




        $output = [];

        // Traverse the structure and generate XML output:
        foreach ($accum as $pId => $page) {
            $output[] = "\t" . '<pageGrp id="' . $pId . '" sourceUrl="' . GeneralUtility::getIndpEnv("TYPO3_SITE_URL") . 'index.php?id=' . $pId . '">' . "\n";
            foreach ($accum[$pId]['items'] as $table => $elements) {
                foreach ($elements as $elementUid => $data) {
                    $skipRecordAsInlineChild = (
                        $this->l10ncfgObj->getData('nest_inline_records')
                        && array_key_exists('isInlineChild', $data)
                        && $data['isInlineChild'] == TRUE
                    );

                    if(! $skipRecordAsInlineChild) {
                        $xmlForRecord = $this->getXmlForRecord($table, $elementUid, $data);
                        $output[] = $xmlForRecord->ownerDocument->saveXML($xmlForRecord);
                    }
                }
            }
            $output[] = "\t" . '</pageGrp>' . "\n";
        }

        // Provide a hook for specific manipulations before building the actual XML
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['exportCatXmlPreProcess'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['exportCatXmlPreProcess'] as $classReference) {
                $processingObject = GeneralUtility::getUserObj($classReference);
                $output = $processingObject->processBeforeExportingCatXml($output, $this);
            }
        }

        // get ISO2L code for source language
        if ($this->l10ncfgObj->getData('sourceLangStaticId') && ExtensionManagementUtility::isLoaded('static_info_tables')) {
            $sourceIso2L = '';
            $staticLangArr = BackendUtility::getRecord('static_languages',
                $this->l10ncfgObj->getData('sourceLangStaticId'), 'lg_iso_2');
            $sourceIso2L = ' sourceLang="' . $staticLangArr['lg_iso_2'] . '"';
        }

        $XML = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $XML .= '<!DOCTYPE TYPO3L10N>' . "\n";
        $XML .= '<TYPO3L10N>' . "\n";
        $XML .= "\t" . '<head>' . "\n";
        $XML .= "\t\t" . '<t3_l10ncfg>' . $this->l10ncfgObj->getData('uid') . '</t3_l10ncfg>' . "\n";
        $XML .= "\t\t" . '<t3_sysLang>' . $sysLang . '</t3_sysLang>' . "\n";
        $XML .= "\t\t" . '<t3_sourceLang>' . $staticLangArr['lg_iso_2'] . '</t3_sourceLang>' . "\n";
        $XML .= "\t\t" . '<t3_targetLang>' . $this->targetIso . '</t3_targetLang>' . "\n";
        $XML .= "\t\t" . '<t3_baseURL>' . GeneralUtility::getIndpEnv("TYPO3_SITE_URL") . '</t3_baseURL>' . "\n";
        $XML .= "\t\t" . '<t3_workspaceId>' . $GLOBALS['BE_USER']->workspace . '</t3_workspaceId>' . "\n";
        $XML .= "\t\t" . '<t3_count>' . $accumObj->getFieldCount() . '</t3_count>' . "\n";
        $XML .= "\t\t" . '<t3_wordCount>' . $accumObj->getWordCount() . '</t3_wordCount>' . "\n";
        $XML .= "\t\t" . '<t3_internal>' . "\n\t" . $this->renderInternalMessage() . "\t\t" . '</t3_internal>' . "\n";
        $XML .= "\t\t" . '<t3_formatVersion>' . L10NMGR_FILEVERSION . '</t3_formatVersion>' . "\n";
        $XML .= "\t\t" . '<t3_l10nmgrVersion>' . L10NMGR_VERSION . '</t3_l10nmgrVersion>' . "\n";
        $XML .= "\t" . '</head>' . "\n";
        $XML .= implode('', $output) . "\n";
        $XML .= "</TYPO3L10N>";

        return $this->saveExportFile($XML);
    }

    /**
     * Renders the list of internal message as XML tags
     *
     * @return string The XML structure to output
     */
    protected function renderInternalMessage()
    {
        $messages = '';
        foreach ($this->internalMessages as $messageInformation) {
            if (!empty($messages)) {
                $messages .= "\n\t";
            }
            $messages .= "\t\t" . '<t3_skippedItem>' . "\n\t\t\t\t" . '<t3_description>' . $messageInformation['message'] . '</t3_description>' . "\n\t\t\t\t" . '<t3_key>' . $messageInformation['key'] . '</t3_key>' . "\n\t\t\t" . '</t3_skippedItem>' . "\n";
        }

        return $messages;
    }

    /**
     * Force a new source language to export the content to translate
     *
     * @param  integer $id
     *
     * @access  public
     * @return  void
     */
    function setForcedSourceLanguage($id)
    {
        $this->forcedSourceLanguage = $id;
    }

    /**
     * @param $table
     * @param $elementUid
     * @param $data
     * @return DOMElement
     */
    protected function getXmlForRecord($table, $elementUid, $data)
    {
        if (!empty($data['ISOcode'])) {
            $this->targetIso = $data['ISOcode'];
        }

        $recordElement = $this->domDocument->createElement('record');
        $recordElement->setAttribute('table', $data['translationInfo']['table']);
        $recordElement->setAttribute('uid', $data['translationInfo']['uid']);

        if (is_array($data['fields'])) {
            foreach ($data['fields'] as $key => $tData) {
                /** @var DOMElement $xmlElement */
                $xmlElement = $this->xmlForField($table, $elementUid, $key, $tData);
                if(! is_null($xmlElement)) {
                    $recordElement->appendChild($xmlElement);
                }

            }
        }

        if (
            $this->l10ncfgObj->getData('nest_inline_records')
            && is_array($data['inlineChildren'])
        ) {
            foreach($data['inlineChildren'] as $fieldName => $records) {

                if(count($records) > 0) {
                    $fieldElement = $this->domDocument->createElement('inlineRecords');
                    $fieldElement->setAttribute('table', $table);
                    $fieldElement->setAttribute('uid', $elementUid);
                    $fieldElement->setAttribute('field', $fieldName);

                    foreach($records as $record) {
                        $fieldElement->appendChild(
                            $this->getXmlForRecord($record['table'], $record['uid'], $record)
                        );
                    }

                    $recordElement->appendChild($fieldElement);
                }

            }
        }

        return $recordElement;
    }

    /**
     * @param $table
     * @param $elementUid
     * @param $tData
     * @param $key
     *
     * @return DOMNode
     */
    protected function xmlForField($table, $elementUid, $key, $tData)
    {
        if (is_array($tData)) {
            list(, $uidString, $fieldName) = explode(':', $key);
            list($uidValue) = explode('/', $uidString);

            $noChangeFlag = !strcmp(trim($tData['diffDefaultValue']), trim($tData['defaultValue']));

            if (!$this->modeOnlyChanged || !$noChangeFlag) {
                if (
                    ! $this->forcedSourceLanguage
                    || (
                        $this->forcedSourceLanguage
                        && isset($tData['previewLanguageValues'][$this->forcedSourceLanguage])
                    )
                ) {
                    if ($this->forcedSourceLanguage) {
                        $dataForTranslation = $tData['previewLanguageValues'][$this->forcedSourceLanguage];
                    } else {
                        $dataForTranslation = $tData['defaultValue'];
                    }

                    // build basic XMLElement for field
                    $attributes = [
                        'table' => $table,
                        'elementUid' => $elementUid,
                        'key' => $key,
                    ];

                    $dataForTranslation = $this->processFieldContent($dataForTranslation, $table, $elementUid, $fieldName);

                    $fieldNode = $this->domDocument->createElement('data');
                    foreach ($attributes as $key => $value) {
                        $fieldNode->setAttribute($key, $value);
                    }

                    $fieldNode->appendChild($this->domDocument->createCDATASection($dataForTranslation));
                    return $fieldNode;
                }
            }
        }

        return null;
    }

    private function processFieldContent($dataForTranslation, $table, $elementUid, $fieldName)
    {
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr'][self::class]['processFieldContent'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr'][self::class]['processFieldContent'] as $classRef) {
                $hookObj = GeneralUtility::getUserObj($classRef);
                if (method_exists($hookObj, 'processFieldContent')) {
                    $dataForTranslation = $hookObj->processFieldContent($dataForTranslation, $table, $elementUid, $fieldName);
                }
            }
        }

        return $dataForTranslation;
    }
}