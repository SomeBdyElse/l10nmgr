<?php
namespace Localizationteam\L10nmgr\Model;

use TYPO3\CMS\Core\FormProtection\Exception;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Function for managing the Import of CAT XML
 *
 * @author  Hannes Lau <office@hanneslau.de>
 *
 * @package TYPO3
 * @subpackage tx_l10nmgr
 */
class CatXmlImporter
{
    /**
     * @var L10nConfiguration
     */
    protected $l10nConfiguration;

    /**
     * @var boolean
     */
    protected $asDefaultLanguage;

    /**
     * @var boolean
     */
    protected $deleteTranslation;

    /**
     * @var boolean
     */
    protected $generatePreviewLink;

    /**
     * @var CatXmlParser
     */
    protected $catXmlParser = NULL;

    /**
     * @var array
     */
    protected $headerData;

    /**
     * @var array
     */
    protected $actionInfo = [];


    /**
     * CatXmlImportManagerRewrite constructor.
     * @param bool $asDefaultLanguage
     * @param bool $deleteTranslation
     * @param bool $generatePreviewLink
     */
    public function __construct(
        $asDefaultLanguage,
        $deleteTranslation,
        $generatePreviewLink
    ) {
        $this->asDefaultLanguage = $asDefaultLanguage;
        $this->deleteTranslation = $deleteTranslation;
        $this->generatePreviewLink = $generatePreviewLink;

        $this->catXmlParser = GeneralUtility::makeInstance(CatXmlParser::class);
    }

    public function importFromFile($uploadedTempFile)
    {
        $parseResult = $this->catXmlParser->parseAndCheckXMLFile($uploadedTempFile);

        if ($parseResult === FALSE) {
            $this->actionInfo[] = $GLOBALS['LANG']->getLL('import.error.title') . implode('<br>' . $this->catXmlParser->getParseErrorMessages());
            return false;
        }

        $this->headerData = $this->catXmlParser->getHeaderData();
        $this->loadL10NConfiguration();


        if ($this->deleteTranslation) {
            $this->logActionInfo($GLOBALS['LANG']->getLL('import.xml.delL10N.message'));
            $rowsToDelete = $this->catXmlParser->getDelL10NDataFromCATXMLNodes();
            $delCount = $this->delL10N($rowsToDelete);
            $this->logActionInfo(sprintf(
                    $GLOBALS['LANG']->getLL('import.xml.delL10N.count.message'),
                    $delCount
            ));
        }

        if ($this->generatePreviewLink) {
            $pageIds = $this->catXmlParser->getPidsFromCATXMLNodes();
            $this->logActionInfo($GLOBALS['LANG']->getLL('import.xml.preview_links.title'));

            /** @var $mkPreviewLinks MkPreviewLinkService */
            $mkPreviewLinks = GeneralUtility::makeInstance(
                MkPreviewLinkService::class,
                $t3_workspaceId = $this->headerData['t3_workspaceId'],
                $t3_sysLang = $this->headerData['t3_sysLang'],
                $pageIds
            );

            $link = $mkPreviewLinks->renderPreviewLinks($mkPreviewLinks->mkPreviewLinks());
            $this->logActionInfo($link);
        }

        /** @var TranslationData $translationData */
        $translationData = $this->catXmlParser->getTranslationData();
        $translationData->setLanguage($this->headerData['t3_sysLang']);

        /** @var L10nBaseService $service */
        $service = GeneralUtility::makeInstance(L10nBaseService::class);

        $service->saveTranslation($this->l10nConfiguration, $translationData);
        $this->logActionInfo($GLOBALS['LANG']->getLL('import.xml.done.message') . '(Command count:' . $service->lastTCEMAINCommandsCount . ')');
    }



    /**
     * Delete previous localisations
     *
     * @return  int    Number of deleted elements
     */
    function delL10N($delL10NData)
    {
        //delete previous L10Ns
        $cmdCount = 0;
        $dataHandler = GeneralUtility::makeInstance('TYPO3\CMS\Core\DataHandling\DataHandler');
        $dataHandler->start(array(), array());
        foreach ($delL10NData as $element) {
            list($table, $elementUid) = explode(':', $element);
            if (isset($GLOBALS['TCA'][$table]['columns']['l10n_parent'])) {
                $where = 'l10n_parent';
            } else {
                $where = 'l18n_parent';
            }
            $where .= "= $elementUid AND sys_language_uid = " . $this->headerData['t3_sysLang'] . " AND t3ver_wsid = " . $this->headerData['t3_workspaceId'];
            if ($table == 'pages') {
                $table = 'pages_language_overlay';
                $where = 'pid = ' . (int)$elementUid  . ' AND sys_language_uid = ' . (int)$this->headerData['t3_sysLang'] . ' AND t3ver_wsid = ' . (int)$this->headerData['t3_workspaceId'];
            }
            $delDataQuery = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('uid', $table, $where, '', '', '', 'uid');
            if(!empty($delDataQuery)) {
                foreach($delDataQuery as $uid => $item) {
                    $dataHandler->deleteAction($table, $uid);
                }
            }
            $cmdCount++;
        }

        return $cmdCount;
    }

    public function getActionInfoAsString()
    {
        return implode('<br />', $this->actionInfo);
    }

    private function logActionInfo($message)
    {
        $this->actionInfo[] = $message;
    }

    private function loadL10NConfiguration()
    {
        // Find l10n configuration record
        /** @var L10nConfiguration $l10ncfgObj */
        $l10ncfgObj = GeneralUtility::makeInstance(L10nConfiguration::class);
        $l10ncfgObj->load($this->headerData['t3_l10ncfg']);
        $status = $l10ncfgObj->isLoaded();
        if ($status === false) {
            throw new Exception('l10ncfg ' . $this->headerData['t3_l10ncfg'] . ' not loaded! Exiting...\n');
        }

        $this->l10nConfiguration = $l10ncfgObj;
    }
}