<?php


namespace Localizationteam\L10nmgr\Model;


class RecordSignature
{
    /**
     * @var string
     */
    public $table;

    /**
     * @var int
     */
    public $uid;

    /**
     * RecordSignature constructor.
     * @param string $table
     * @param int $uid
     */
    public function __construct($table, $uid)
    {
        $this->table = $table;
        $this->uid = $uid;
    }

    public function toString() {
        return $this->table . ':' . $this->uid;
    }
}