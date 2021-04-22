<?php namespace App\Controllers;

use CodeIgniter\Events\Events;
use App\Models\FolderModel;
use App\Models\DocumentModel;
use App\Models\RecordMemberModel;

class DocumentsController extends BaseController
{
    public function all_v1()
	{
        $user = $this->request->user;

        // get all the folders
        $folderModel = new FolderModel();
        $folderBuilder = $folderModel->builder();
        $folderQuery = $folderBuilder->select("folders.id, folders.title, folders.created, folders.updated")
            ->join("folders_members", "folders_members.folder = folders.id", "left")
            ->where("folders.deleted", NULL)
            ->groupStart()
                ->where("folders.owner", $user->id)
                ->orWhere("folders_members.user", $user->id)
                ->orWhere("folders.everyone", 1)
            ->groupEnd()
            ->groupBy("folders.id")
            ->orderBy("folders.order", "ASC")
            ->get();
        $folders = $folderQuery->getResult();

        // get all the records
        $documentModel = new DocumentModel();
        $documentBuilder = $documentModel->builder();
        $documentQuery = $documentBuilder->select("documents.id, documents.title, documents.type, documents.updated, documents.created, documents.folder, documents.order")
            ->join("documents_members", "documents_members.document = documents.id", "left")
            // ->join("documents_order", "documents_members.document = documents.id", "left")
            ->where("documents.deleted", NULL)
            ->groupStart()
                ->where("documents.owner", $user->id)
                ->orWhere("documents_members.user", $user->id)
                ->orWhere("documents.everyone", 1)
            ->groupEnd()
            ->groupBy("documents.id")
            ->orderBy("documents.order", "ASC")
            ->get();
        $documents = $documentQuery->getResult();

        foreach ($folders as &$folder) {
            if (!isset($folder->records)) {
                $folder->records = array();
            }

            foreach ($documents as &$document) {
                if ($document->folder === $folder->id) {
                    unset($document->folder);
                    unset($document->order);
                    $folder->records[] = $document;
                }
            }
        }

        return $this->reply($folders);
    }

	public function one_v1($id)
	{
        $user = $this->request->user;
        helper("documents");

        $document = documents_load_document($id, $user);
        $data = null;

        switch ($document->type) {
            case "project":
                helper("projects");
                $data = projects_load($document);
                break;
            
            default:
                # code...
                break;
        }

        return $this->reply($data);
    }

    public function add_v1()
    {
        $user = $this->request->user;
        $recordData = $this->request->getJSON();
        $recordData->owner = $user->id;

        helper("documents");

        // check for unknown record types
        if (!documents_validate_type($recordData->type)) {
            return $this->reply("Document type missing or not valid", 500, "ERR-DOCUMENTS-CREATE");
        }

        if ($recordData->type === "folder") {
            $result = documents_create_folder($recordData);
        } else {
            if (!isset($recordData->folder)) {
                return $this->reply("Missing folder id", 500, "ERR-DOCUMENTS-CREATE");
            }

            // TODO: check permission on folder

            $result = documents_create_document($recordData);
        }

        if ($result === true) {
            return $this->reply(true);
        }

        return $this->reply($result, 500, "ERR-DOCUMENTS-CREATE");
    }

    public function update_v1()
    {
        $recordData = $this->request->getJSON();
        // check for unknown record types
        if (!isset($recordData->id)) {
            return $this->reply("Document `id` missing or not valid", 500, "ERR-DOCUMENTS-CREATE");
        }

        // $this->lock($recordData->id);

        helper("documents");

        // check for unknown record types
        if (!documents_validate_type($recordData->type)) {
            return $this->reply("Document `type` missing or not valid", 500, "ERR-DOCUMENTS-CREATE");
        }

        if ($recordData->type === "folder") {
            // TODO: check for folder permission to edit
            $result = documents_update_folder($recordData);
        } else {
            $result = documents_update_document($recordData);
        }

        if ($result === true) {
            return $this->reply(true);
        }

        return $this->reply($result, 500, "ERR-DOCUMENTS-UPDATE");
    }

    public function order_v1()
    {
        $orderData = $this->request->getJSON();
        $db = db_connect();
        /*
        INSERT INTO stk_folders (id, `order`)
        VALUES ('7d2ea375-725f-402f-a152-a0a3afd36a12', 100),
        ('d9da620c-d4e9-429a-9a6b-e3f6d8d1f9f7', 444)
        ON DUPLICATE KEY UPDATE 
        id=VALUES(id), 
        `order`=VALUES(`order`);
        */

        // get all folders
        $folderModel = new FolderModel();
        $folders = $folderModel->orderBy("order", "asc")->findAll();
        
        // get all documents
        $documentModel = new DocumentModel();
        $documents = $documentModel->orderBy("order", "asc")->findAll();

        $foldersNeedUpdate = array();
        $documentsNeedUpdate = array();

        $folderOrder = 1;
        
        foreach ($orderData as $folderID => $documentsOrder) {
            foreach ($folders as &$currentFolder) {
                if ($currentFolder->id === $folderID && $currentFolder->order != $folderOrder) {
                    $currentFolder->order = $folderOrder;
                    $foldersNeedUpdate[] = $currentFolder;
                }
            }

            $documentOrder = 1;

            foreach ($documentsOrder as $documentID) {
                foreach ($documents as &$document) {
                    if ($document->id === $documentID && ($document->order != $documentOrder || $document->folder != $folderID)) {
                        $document->order = $documentOrder;
                        $document->folder = $folderID;
                        $documentsNeedUpdate[] = $document;
                    }
                }

                $documentOrder++;
            }
            

            $folderOrder++;
        }

        // update folders order
        if (count($foldersNeedUpdate)) {
            $foldersOrderQuery = array(
                "INSERT INTO ".$db->prefixTable("folders")." (`id`, `order`) VALUES"
            );

            foreach ($foldersNeedUpdate as $i => $folder) {
                $value = "(". $db->escape($folder->id) .", ". $db->escape($folder->order) .")";
                if ($i < count($foldersNeedUpdate) - 1) {
                    $value .= ",";
                }
                $foldersOrderQuery[] = $value;
            }

            $foldersOrderQuery[] = "ON DUPLICATE KEY UPDATE id=VALUES(id), `order`=VALUES(`order`);";
            $foldersQuery = implode(" ", $foldersOrderQuery);

            if (!$db->simpleQuery($foldersQuery)) {
                return $this->reply("Unable to update folders order", 500, "ERR-DOCUMENTS-REORDER");
            }
        }

        // update documents order
        if (count($documentsNeedUpdate)) {
            $documentsOrderQuery = array(
                "INSERT INTO ".$db->prefixTable("documents")." (`id`, `folder`, `order`) VALUES"
            );

            foreach ($documentsNeedUpdate as $i => $document) {
                $value = "(". $db->escape($document->id) .", ". $db->escape($document->folder) .", ". $db->escape($document->order) .")";
                if ($i < count($documentsNeedUpdate) - 1) {
                    $value .= ",";
                }
                $documentsOrderQuery[] = $value;
            }

            $documentsOrderQuery[] = "ON DUPLICATE KEY UPDATE id=VALUES(id), `folder`=VALUES(`folder`), `order`=VALUES(`order`);";
            $documentsQuery = implode(" ", $documentsOrderQuery);

            if (!$db->simpleQuery($documentsQuery)) {
                return $this->reply("Unable to update documents order", 500, "ERR-DOCUMENTS-REORDER");
            }
        }

        return $this->reply(true);
    }

    private function add_update_v1($update = false)
    {
        helper('uuid');

        $user = $this->request->user;
        $recordData = $this->request->getJSON();

        // create record object
        $record = new \stdClass();
        $record->id = $recordData->id;
        $record->title = $recordData->title;
        $record->owner = $user->id;
        $record->type = "project";

        // in case everyone was not set will enforce it to 1
        if (!isset($recordData->everyone)) {
            $record->everyone = 1;
        } else {
            $record->everyone = intval($recordData->everyone);
        }

        // setting the record type
        if (isset($recordData->type)) {
            $record->type = $recordData->type;
        }

        // set record ID in case is missing
        if (!isset($record->id)) {
            $record->id = uuid();
        }

        // getting the members array
        // and setting everyone to 0 in case there are members
        $membersIDs = array();
        if (isset($recordData->members)) {
            $membersIDs = $recordData->members;
            $record->everyone = 0;
        }

        // $recordData->archived_order = 'title-asc';
        // if (!isset($recordData->hourlyFee)) {
        //     $recordData->hourlyFee = 0;
        // }
        // if (!isset($recordData->feeCurrency)) {
        //     $recordData->feeCurrency = "USD";
        // }
        // if (isset($recordData->tags)) {
        //     if (!$this->set_tags($recordData->id, $recordData->tags)) {
        //         return $this->reply(null, 500, "ERR-BOARD-TAGS");   
        //     }
        // }
        
        // save the record
        $DocumentModel = new DocumentModel();
        
        if ($update) {
            try {
                if ($DocumentModel->update($record->id, $record) === false) {
                    $errors = $DocumentModel->errors();
                    // TODO: need to change the error message based on the type of the record updated
                    return $this->reply($errors, 500, "ERR-RECORD-UPDATE");
                }
            } catch (\Exception $e) {
                // TODO: need to change the error message based on the type of the record updated
                return $this->reply($e->getMessage(), 500, "ERR-RECORD-UPDATE");
            }

            Events::trigger("AFTER_record_UPDATE", $record->id);
        } else {
            try {
                if ($DocumentModel->insert($record) === false) {
                    $errors = $DocumentModel->errors();
                    // TODO: need to change the error message based on the type of the record created
                    return $this->reply($errors, 500, "ERR-RECORD-CREATE");
                }
            } catch (\Exception $e) {
                // TODO: need to change the error message based on the type of the record created
                return $this->reply($e->getMessage(), 500, "ERR-RECORD-CREATE");
            }
        
            // assign record to folder
            $recordOrder = new \stdClass();
            $recordOrder->folder = $recordData->folder;
            $recordOrder->record = $record->id;
            $recordOrder->order = 1;

            $recordOrderModel = new RecordOrderModel();
            $lastOrder = $recordOrderModel->where("folder", $recordData->folder)
                ->orderBy("order", "desc")
                ->first();

            if ($lastOrder) {
                $recordOrder->order = intval($lastOrder->order) + 1;
            }

            try {
                if ($recordOrderModel->insert($recordOrder) === false) {
                    $errors = $recordOrderModel->errors();
                    return $this->reply($errors, 500, "ERR-RECORD-ORDER");
                }
            } catch (\Exception $e) {
                return $this->reply($e->getMessage(), 500, "ERR-RECORD-ORDER");
            }

            // if members are defined then assigned them to the board
            if (count($membersIDs)) {
                $recordMemberModel = new RecordMemberModel();
                $recordMemberBuilder = $recordMemberModel->builder();

                $members = array();
                foreach ($membersIDs as $userID) {
                    $members[] = [
                        'record' => $record->id,
                        'user' => $userID
                    ];
                }

                try {
                    if ($recordMemberBuilder->insertBatch($members) === false) {
                        $errors = $recordMemberBuilder->errors();
                        return $this->reply($errors, 500, "ERR-RECORD-MEMBERS");    
                    }
                } catch (\Exception $e) {
                    return $this->reply($e->getMessage(), 500, "ERR-RECORD-MEMBERS");
                }
            }

            Events::trigger("AFTER_record_ADD", $record->id);
        }

        return $this->reply($record, 200, "OK-RECORD-CREATE-SUCCESS");
    }
}
