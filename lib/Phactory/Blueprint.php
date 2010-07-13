<?

class Phactory_Blueprint {
    protected $_table;
    protected $_defaults;
    protected $_associations = array();
    protected $_sequence;

    public function __construct($name, $defaults, $associations = array()) {
        $this->_table = new Phactory_Table($name); 
        $this->_defaults = $defaults;
        $this->_sequence = new Phactory_Sequence();

        foreach($associations as $name => $association) {
            $this->addAssociation($name, $association);
        }

        if(!is_array($associations)) {
            throw new Exception("\$associations must be an array of Phactory_Association objects");
        }
    }

    public function setDefaults($defaults) {
        $this->_defaults = $defaults;
    }

    public function addDefault($column, $value) {
        $this->_defaults[$column] = $value;
    }

    public function removeDefault($column) {
        unset($this->_defaults[$column]);
    }

    public function setAssociations($associations) {
        $this->_associations = $associations;
    }

    public function addAssociation($name, $association) {
        $association->setFromTable($this->_table);
        $this->_associations[$name] = $association;
    }

    public function removeAssociation($name) {
        unset($this->_associations[$name]);
    }

    /*
     * Reify a Blueprint as a Phactory_Row. Optionally use an array
     * of associated objects to set fk columns.
     *
     * @param array $associated  [table name] => [Phactory_Row]
     */
    public function create($overrides = array(), $associated = array()) {
        $assoc_keys = array();
        $many_to_many = array();
        if($associated) {
            foreach($associated as $name => $row) {
                if(!isset($this->_associations[$name])) {
                    throw new Exception("No association '$name' defined");
                }

                $association = $this->_associations[$name];

                if($association instanceof Phactory_Association_ManyToMany) {
                    $many_to_many[$name] = array($row, $association);
                } else {
                    $fk_column = $association->getFromColumn();
                    $assoc_keys[$fk_column] = $row->getId();
                }
            }
        }
    
        $data = array_merge($this->_defaults, $assoc_keys);

        $this->_evalSequence($data);

        $row = new Phactory_Row($this->_table, $data); 

        if($overrides) {
            foreach($overrides as $field => $value) {
                $row->$field = $value;
            }
        }
     
        $row->save();
        
        if($many_to_many) {
            $this->_associateManyToMany($row, $many_to_many);
        }

        return $row;
    }

    /*
     * Truncate table in the database.
     */
    public function recall() {
        $db_util = Phactory_DbUtilFactory::getDbUtil();
        $db_util->disableForeignKeys();

    	try {
            $sql = "DELETE FROM {$this->_table->getName()}";
            Phactory::getConnection()->exec($sql);
        } catch(Exception $e) { }

        foreach($this->_associations as $association) {
            if($association instanceof Phactory_Association_ManyToMany) {
                try {
                    $sql = "DELETE FROM {$association->getJoinTable()}";
                    Phactory::getConnection()->exec($sql);
                } catch(Exception $e) { }
            }
        }

        $db_util->enableForeignKeys();
    }

    protected function _evalSequence(&$data) {
        $n = $this->_sequence->next();
        foreach($data as &$value) {
            if(false !== strpos($value, '$')) {
                $value = eval('return "'. stripslashes($value) . '";');
            }
        }
    }

    protected function _associateManyToMany($row, $many_to_many) {
        $pdo = Phactory::getConnection();
        foreach($many_to_many as $name => $arr) {
            list($to_row, $assoc) = $arr;
            $join_table = $assoc->getJoinTable();
            $from_join_column = $assoc->getFromJoinColumn();
            $to_join_column = $assoc->getToJoinColumn();
			
            $sql = "INSERT INTO `$join_table` 
                    (`$from_join_column`, `$to_join_column`)
                    VALUES
                    (:from_id, :to_id)";
            $stmt = $pdo->prepare($sql);
            $r = $stmt->execute(array(':from_id' => $row->getId(), ':to_id' => $to_row->getId()));
			
			if($r === false){
				$errorInfo = $stmt->errorInfo();
				throw new Exception('The following INSERT statement failed: '.$sql.' ERROR MESSAGE: '.$errorInfo[2].' ERROR CODE: '.$errorInfo[1]);
			}
        }
    }
}
