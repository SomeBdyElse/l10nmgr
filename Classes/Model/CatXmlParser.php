<?php


namespace Localizationteam\L10nmgr\Model;


use DOMElement;
use DOMNode;
use DOMXPath;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class CatXmlParser
{
    /**
     * @var \DOMDocument
     */
    protected $domDocument;

    /**
     * @var array
     */
    protected $headerData;

    /**
     * @var array
     */
    protected $parseErrorMessages = [];


    /**
     * @param $path
     *
     * @return boolean success
     */
    public function parseAndCheckXMLFile($path)
    {
        $domDocument = new \DOMDocument();
        $loadSuccess = $domDocument->load($path);

        if(!$loadSuccess) {
            $this->parseErrorMessages[] = $GLOBALS['LANG']->getLL('import.manager.error.parsing.xml2tree.message');
            return FALSE;
        }

        $this->domDocument = $domDocument;

        $xpath = new DOMXPath($this->domDocument);
        $headNodes = $xpath->query("/TYPO3L10N/head");

        if(! $headNodes->length > 0) {
            $this->parseErrorMessages[] = $GLOBALS['LANG']->getLL('import.manager.error.missing.head.message');
            return FALSE;
        }

        $headNode = $headNodes->item(0);

        $this->setHeaderDataFromHeadNode($headNode);

        if (! $this->validateHeaderInformation()) {
            return FALSE;
        }

        return TRUE;
    }

    /**
     * @return bool headerData is valid
     */
    function validateHeaderInformation()
    {
        global $LANG;
        $error = array();

        if (!isset($this->headerData['t3_formatVersion']) || $this->headerData['t3_formatVersion'] != L10NMGR_FILEVERSION) {
            $error[] = sprintf(
                $LANG->getLL('import.manager.error.version.message'),
                $this->headerData['t3_formatVersion'],
                L10NMGR_FILEVERSION
            );
        }
        if (!isset($this->headerData['t3_workspaceId']) || $this->headerData['t3_workspaceId'] != $GLOBALS['BE_USER']->workspace) {
            $GLOBALS['BE_USER']->workspace = $this->headerData['t3_workspaceId'];
            $error[] = sprintf(
                $LANG->getLL('import.manager.error.workspace.message'),
                $GLOBALS['BE_USER']->workspace,
                $this->headerData['t3_workspaceId']
            );
        }
        if (count($error) > 0) {
            $this->parseErrorMessages = array_merge($this->parseErrorMessages, $error);
            return FALSE;
        }

        return TRUE;
    }

    /**
     * @param $headNode
     */
    private function setHeaderDataFromHeadNode($headNode)
    {
        $headerData = [];
        /** @var DOMNode $childNode */
        foreach ($headNode->childNodes as $childNode) {
            if ($childNode->nodeType !== XML_TEXT_NODE) {
                $key = $childNode->nodeName;
                $value = $childNode->nodeValue;
                $headerData[$key] = $value;
            }
        }

        $this->headerData = $headerData;
    }

    /**
     * Get uids for which localizations shall be removed on 2nd import if option checked
     *
     * @return  array    Uids for which localizations shall be removed
     */
    function getDelL10NDataFromCATXMLNodes()
    {
        $rows = $this->getDataRows();

        /** @var DOMElement $rowNode */
        $delL10NUids = [];
        foreach($rows as $rowNode) {
            $table = $rowNode->getAttribute('table');
            $elementUid = $rowNode->getAttribute('elementUid');
            $key = $rowNode->getAttribute('key');

            if (preg_match('/NEW/', $key)) {
                $delL10NUids[] = $table . ':' . $elementUid;
            }

        }

        return array_unique($delL10NUids);
    }


    /**
     * @return array page uids mentioned within the translation file
     */
    public function getPidsFromCATXMLNodes()
    {
        $pageGrpNodes = $this->getPageGrps();

        $pids = [];
        /** @var DOMElement $pageGrpNode */
        foreach($pageGrpNodes as $pageGrpNode) {
            $pids[] = $pageGrpNode->getAttribute('id');
        }

        return $pids;
    }


    private function getPageGrps()
    {
        $xpath = new DOMXPath($this->domDocument);
        $rowNodes = $xpath->query('//pageGrp');
        return $rowNodes;
    }


    private function getDataRows()
    {
        $xpath = new DOMXPath($this->domDocument);
        $rowNodes = $xpath->query('//data');
        return $rowNodes;
    }


    /**
     * Returns the translated values within the document
     *
     * @return TranslationData
     **/
    function getTranslationData()
    {
        $translationData = array();

        $dataNodes = $this->getDataRows();
        /** @var DOMElement $dataNode */
        foreach ($dataNodes as $dataNode) {
            $table = $dataNode->getAttribute('table');
            $elementUid = $dataNode->getAttribute('elementUid');
            $key = $dataNode->getAttribute('key');

            $translationData[$table][$elementUid][$key] = $this->getTranslationFromDataNode($dataNode);
        }

        /** @var TranslationData $translationDataObject */
        $translationDataObject = GeneralUtility::makeInstance(TranslationData::class);
        $translationDataObject->setTranslationData($translationData);

        return $translationDataObject;
    }



    /**
     * @param $dataNode
     *
     * @return mixed
     */
    private function getTranslationFromDataNode(DOMElement $dataNode)
    {
        $value = $dataNode->nodeValue;
        return $value;
    }

    /**
     * @return array
     */
    public function getParseErrorMessages()
    {
        return $this->parseErrorMessages;
    }

    /**
     * @return array
     */
    public function getHeaderData()
    {
        return $this->headerData;
    }
}