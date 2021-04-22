<?php namespace App\Models;

use CodeIgniter\Model;

class FolderModel extends Model
{
    protected $table      = "folders";
    protected $primaryKey = "id";
    protected $returnType = "object";

    protected $useSoftDeletes = true;

    protected $allowedFields = ["id", "title", "owner", "order"];

    protected $useTimestamps = true;
    protected $createdField  = "created";
    protected $updatedField  = "updated";
    protected $deletedField  = "deleted";

    protected $validationRules = [
        "id" => "required|min_length[35]",
        "title" => "required|alpha_numeric_punct",
        "owner" => "required",
        "order" => "required|numeric"
    ];

    protected $validationMessages = [
        "id" => [
            "required" => "Missing required field `id`",
            "min_length" => "Invalid field `id`",
        ],
        "title" => [
            "required" => "Missing required field `title`",
        ],
        "owner" => [
            "required" => "Missing required field `owner`"
        ],
        "order" => [
            "required" => "Missing required field `order`"
        ]
    ];
}