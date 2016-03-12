<?php


namespace Localizationteam\L10nmgr\Model\Tools;


use DOMDocument;
use DOMNode;

class DOMTools
{
    function appendHTML(DOMNode $parent, $source) {
        $tmpDoc = new DOMDocument();
        $tmpDoc->loadHTML('<?xml encoding="utf-8" ?>' . $source);
        foreach ($tmpDoc->getElementsByTagName('body')->item(0)->childNodes as $node) {
            $node = $parent->ownerDocument->importNode($node, TRUE);
            $parent->appendChild($node);
        }
    }
}