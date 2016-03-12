<?php


namespace Localizationteam\L10nmgr\Model;


class InlineRelation
{

    public $parentTable;
    public $parentField;
    public $parentTCAConf;

    public $foreign_table;
    public $foreign_field;

    public $foreign_table_field;
    public $foreign_match_fields;


    /**
     * InlineRelation constructor.
     * @param $parentTable
     * @param $parentField
     * @param $foreign_table
     * @param $foreign_field
     * @param $foreign_table_field
     * @param $foreign_match_fields
     */
    public function __construct(
        $parentTable,
        $parentField,
        $parentTCAConf,
        $foreign_table,
        $foreign_field,
        $foreign_table_field = '',
        $foreign_match_fields = NULL
    ) {
        $this->parentTable = $parentTable;
        $this->parentField = $parentField;
        $this->parentTCAConf = $parentTCAConf;
        $this->foreign_table = $foreign_table;
        $this->foreign_field = $foreign_field;
        $this->foreign_table_field = $foreign_table_field;
        $this->foreign_match_fields = $foreign_match_fields;
    }
}