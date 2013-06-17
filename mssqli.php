<?php

    /**
	* MySQLi like MS SQL Class
	*
	* @author andy wotherspoon - for Score Communications
	* @version    Release: 1.0.0
	*/
  
class mssqli
{
    private $debug;
    private $pdo;
    private $db_type;
    private $db_prefix;
    private $mssql;
    private $stmt;
    private $query;
    private $query_type;
    private $select;
    private $table;
    private $set;
    private $where;
    private $limit;
    private $order;
    private $results;
    
    // public variables
    public $affected_rows = 0;
    public $insert_id = null;
    public $num_rows = 0;
    public $error = null;
    
    function __construct($type,$host,$user,$password,$db,$prefix=null,$debug=false)
    {
        /* Connect to an ODBC database using driver invocation */
        
        if($type=='sqlsrv')
        {
            $dsn = $type . ':Server=' . $host . ';Database=' . $db;
        }
        else
        {
            $dsn = $type . ':host=' . $host . ';dbname=' . $db;
        }
        
        try {
            $this->debug = $debug ; 
            $this->pdo = new PDO($dsn, $user, $password);
            $this->db_type = $type;
            $this->db_prefix = $prefix;
            
        } catch (PDOException $e) {
            if(!$debug)
            {
                echo 'Connection failed: ' . $e->getMessage() . $e->getCode();
            }
            else
            {
                echo $_SERVER['REQUEST_URI'];
                if(!preg_match('#/?error\.php#',$_SERVER['REQUEST_URI']))
                {
                    header("Location: /error.php");
                }
            }
        }
    }
    
    function test()
    {
        if($this->debug){ echo '<pre>STORED PRO TEST:</pre>'; }
        
        $value = 162;
        
        $stmt = $this->pdo->prepare("EXECUTE dbo.usp_300_006_001_GetScoreCommsApps ?");
        debug_print($this->pdo->errorInfo());
        
        $stmt->bindParam(1, $value, PDO::PARAM_INT, 4);
                
        // call the stored procedure
        $stmt->execute();
                
        debug_print($stmt->errorInfo());        
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo count($rows);
        
        debug_print($rows);
    }
            
    function eval_query($query)
    {
    	// If query has UNION in separate out the quesries and process them separately
        if(preg_match('# *UNION( *ALL)? *#',$query))
        {
            preg_match_all('#(?:\((?:(?<type>SELECT|INSERT|UPDATE|DELETE)(?:(?: *(?<select>[a-zA-z_\., \*\'-]*) *FROM)|(?:[^a-z_-]*))? *(?<table>(?:[a-z_\.-]+( *AS *[^ ,]+)?( *, *)?)+) *(?:SET *(?<set>(?:(?:[a-zA-Z0-9_-]*) *= *(?:\?|(?:\'?[0-9A-Za-z_ -]*\'?))(?: *, *)?)*))? *(?:WHERE *(?<where>(?:(?:[a-zA-Z0-9_\.\(\)-]*) *(?:(?:(?:(?:[/!=<>]+| *IS *(?:NOT)? *NULL *| *LIKE *)) *(?:\?|\'[^\']*\'|[^ ]*))|(?:BETWEEN *[0-9]+ *AND *[0-9]+))(?: *(?:AND|OR) *)?)*(?: *\))?))? *(?:ORDER BY *(?<order>[a-zA-Z0-9_ -\(\)\.\,]*?))? *(?:LIMIT *((?<offset>[0-9]+), *)?(?<limit>[0-9]+?))?);?\)(?<tail> *UNION(?: *ALL)? *)?)#',$query,$query_params_array);
            
            $query_params_array = flip_array($query_params_array);
            
            if($this->debug){ debug_print($query_params_array); }
            
            foreach($query_params_array as $key => $query_params)
            {
                preg_match_all('#(?: *, *)?(?<select>[a-z_]+)#',$query_params['select'],$select);
                preg_match_all('#(?:(?: *, *)?(?:(?<fields>[a-zA-Z0-9_-]+) *= *(?<values>\?|\'[^\',]*\'|[^,]*) *,?))#',$query_params['set'],$set);
                preg_match_all('#(?:(?: *(?:AND|OR|\|\||&&) *)?(?:(?:\)|\() *)?(?:(?<fields>[a-zA-Z0-9_-]+?) *(?<operator>(?:[/!=<>]+| *IS *NOT *NULL *| *LIKE *)) *(?<values>\?|\'[^\'\)]*\'|[^\' \)]*)))#',$query_params['where'],$where);
                        
                $this->query->type = mb_convert_case($query_params_array[$key]['type'],MB_CASE_LOWER);
                $this->query->select->sql = $query_params['select'];
                $this->query->select->fields = $select['select'];
                $this->query->table = $query_params_array[$key]['table'];
                $this->query->set->sql = $query_params['set'];
                $this->query->set->fields = $set['fields'];
                $this->query->set->vals = $set['values'];
                $this->query->where->sql = $query_params['where'];
                $this->query->where->fields = $where['fields'];
                $this->query->where->ops = $where['operator'];
                $this->query->where->vals = $where['values'];
                $this->query->order = $query_params_array[$key]['order'];
                $this->query->offset = $query_params_array[$key]['offset'];
                $this->query->limit = $query_params_array[$key]['limit'];
                $this->query->tail = $query_params_array[$key]['tail'];
                                   
                if(preg_match('#^(dblib|sqlsrv)$#', $this->db_type))
                {
                	// If a SELECT with OFFSET convert to use ROW_NUMBER() to use as offset
                    if($this->query->type=='select'&&($this->query->offset=='0'||$this->query->offset>0))
                    {   
                        $query_params[0] = "(SELECT * FROM (SELECT " . $this->query->select->sql . ", ROW_NUMBER() OVER (ORDER BY " . ( $this->query->order ? $this->query->order : 'ID' ) . ") AS RowNum FROM " .  $this->query->table . " WHERE " . $this->query->where->sql .") AS full_selection WHERE full_selection.RowNum BETWEEN " . ($this->query->offset + 1) . " AND " . ($this->query->limit + $this->query->offset) . ")" . $this->query->tail;
                    }
                    // else if a LIMIT is used with no offset use SELECT TOP instead
                    elseif($this->query->limit)
                    {
                        $query_params[0] = preg_replace_callback('#.*? *LIMIT *([0-9]+?, *)?([0-9]+)#',create_function(
                            '$limit',
                            'return preg_replace(array(\'#^SELECT *#\',\'# *LIMIT *([0-9]+?, *)?([0-9]+)#\'),array(\'SELECT TOP \'.$limit[2].\' \',\'\'),$limit[0]);'
                        ),$query_params[0]);
                    }
                
                    // If an INSERT replace SET with () VALUES () as SET not supported for INSERT in MSSQL
	                if($this->query->type=='insert')
                    {
                        $set_replace = '(' . implode(', ',$this->query->set->fields) . ') VALUES (' . implode(', ',$this->query->set->vals) . ') ';
                        $query_params[0] = preg_replace('#SET *'.str_replace('?','\?',$query_params['set']).'#', $set_replace, $query_params[0]);
                    }
                    
                    // If table doesn't have a prefix for MSSQL, add it
                    if(strpos($this->query->table, $this->db_prefix)===false)
                    {  
                        $table_replace = preg_replace('#([^ ]+) *(AS *[^ ,]+( *, *)?)?#', $this->db_prefix.'$1 $2', $this->query->table);
                        $query_params[0] = str_replace($this->query->table, $table_replace, $query_params[0]);
                    }
                    
                    // if RAND() used replace with NEWID()
                    if(preg_match('#RAND\(([^\)]*)\)#',$this->query->order))
                    {
                        $query_params[0] = preg_replace('#RAND\(([^\)]*)\)#','NEWID($1)',$query_params[0]);
                    }
                                
                    // If ORDER BY used, except for with ROW_NUMBER() OVER(), remove and save to be added at end of query
                    if(preg_match('# *ORDER *#',$query_params[0]))
                    {
                        $query_params[0] = preg_replace('# *(?<!OVER \()ORDER BY *' . $this->query->order . '#','',$query_params[0]);
                    
                        $order_by = ' ORDER BY '.$this->query->order;
                    }
                }
                                
                $query_params_array[$key][0] = $query_params[0];
            }
            
            $query_params_array = flip_array($query_params_array);
                        
            $query = implode('',$query_params_array[0]).$order_by;
        }
        // else if it's a standrd query process everything in one go
        else
        {
            preg_match('#^(?:(?<type>SELECT|INSERT|UPDATE|DELETE)(?:(?: *(?<select>[a-zA-z_\., \*-]*) *FROM)|(?:[^a-z_-]*))? *(?<table>(?:[a-z_\.-]+( *AS *[^ ,]+)?( *, *)?)+) *(?:SET *(?<set>(?:(?:[a-zA-Z0-9_-]*) *= *(?:\?|(?:\'?[0-9A-Za-z_ -]*\'?))(?: *, *)?)*))? *(?:WHERE *(?<where>(?:(?:[a-zA-Z0-9_\.\(\)-]*) *(?:(?:(?:(?:[/!=<>]+| *IS *(?:NOT)? *NULL *| *LIKE *)) *(?:\?|\'[^\']*\'|[^ ]*))|(?:BETWEEN *[0-9]+ *AND *[0-9]+))(?: *(?:AND|OR) *)?)*(?: *\))?))? *(?:ORDER BY *(?<order>[a-zA-Z0-9_ -\(\)\.\,]*?))? *(?:LIMIT *((?<offset>[0-9]+), *)?(?<limit>[0-9]+?))?)+;?$#',$query,$query_params);
                    
            preg_match_all('#(?: *, *)?(?<select>[a-z_]+)#',$query_params['select'],$select);
            preg_match_all('#(?:(?: *, *)?(?:(?<fields>[a-zA-Z0-9_-]+) *= *(?<values>\?|\'[^\',]*\'|[^,]*) *,?))#',$query_params['set'],$set);
            preg_match_all('#(?:(?: *(?:AND|OR|\||&&) *)?(?:(?:\)|\() *)?(?:(?<fields>[a-zA-Z0-9_-]+?) *(?<operator>(?:[/!=<>]+| *IS *NOT *NULL *| *LIKE *)) *(?<values>\?|\'[^\'\)]*\'|[^\' \)]*)))#',$query_params['where'],$where);
                        
            $this->query->type = mb_convert_case($query_params['type'],MB_CASE_LOWER);
            $this->query->select->sql = $query_params['select'];
            $this->query->select->fields = $select['select'];
            $this->query->table = $query_params['table'];
            $this->query->set->sql = $query_params['set'];
            $this->query->set->fields = $set['fields'];
            $this->query->set->vals = $set['values'];
            $this->query->where->sql = $query_params['where'];
            $this->query->where->fields = $where['fields'];
            $this->query->where->ops = $where['operator'];
            $this->query->where->vals = $where['values'];
            $this->query->order = $query_params['order'];
            $this->query->offset = $query_params['offset'];
            $this->query->limit = $query_params['limit'];
                                
            if(preg_match('#^(dblib|sqlsrv)$#',$this->db_type))
            {
                // If a SELECT with OFFSET convert to use ROW_NUMBER() to use as offset
                if($this->query->type=='select'&&($this->query->offset=='0'||$this->query->offset>0))
                {   
                    $query = "SELECT * FROM (SELECT " . $this->query->select->sql . ", ROW_NUMBER() OVER (ORDER BY " . ($this->query->order ? $this->query->order : 'ID') .") AS RowNum FROM " .  $this->query->table . " WHERE " . $this->query->where->sql .") AS full_selection WHERE full_selection.RowNum BETWEEN " . ($this->query->offset + 1) . " AND " . ($this->query->limit + $this->query->offset) . ($this->query->order ? " ORDER BY " . $this->query->order : "");
                }
                // else if a LIMIT is used with no offset use SELECT TOP instead
                elseif($this->query->limit)
                {
                    $query = preg_replace_callback('#.*? *LIMIT *([0-9]+?, *)?([0-9]+)#',create_function(
                        '$limit',
                        'return preg_replace(array(\'#^SELECT *#\',\'# *LIMIT *([0-9]+?, *)?([0-9]+)#\'),array(\'SELECT TOP \'.$limit[2].\' \',\'\'),$limit[0]);'
                    ),$query);
                }
                
                // If an INSERT replace SET with () VALUES () as SET not supported for INSERT in MSSQL
                if($this->query->type=='insert')
                {
                    $set_replace = '(' . implode(', ',$this->query->set->fields) . ') VALUES (' . implode(', ',$this->query->set->vals) . ') ';
                    $query = preg_replace('#SET *'.str_replace('?','\?',$query_params['set']).'#', $set_replace, $query);
                }
            
                // If table doesn't have a prefix for MSSQL, add it
                if(strpos($this->query->table,$this->db_prefix)===false)
                {  
                    $table_replace = preg_replace('#([^ ]+) *(AS *[^ ,]+( *, *)?)?#',$this->db_prefix.'$1 $2',$this->query->table);
                    $query = str_replace($this->query->table, $table_replace, $query);
                }
                
                // if RAND() used replace with NEWID()
                if(preg_match('#RAND\(([^\)]*)\)#',$this->query->order))
                {
                    $query = preg_replace('#RAND\(([^\)]*)\)#','NEWID($1)',$query);
                }
            }
        }

        /*if($this->debug)
        {
            echo '<pre>query:<br />'.$query.'</pre>';
            
            debug_print($query_params);

            echo '<pre>type:<br />'.$query_params['type'].'</pre>';
            echo '<pre>select: </pre>';
            debug_print($this->query->select);
            echo '<pre>table:<br />'.$query_params['table'].'</pre>';
            echo '<pre>set: </pre>';
            debug_print($this->query->set);
            echo '<pre>where: </pre>';
            debug_print($this->query->where);
            echo '<pre>----------------------------------------------------------------------------------------------------</pre>'; 
        }*/
                
        $this->query->sql = $query;
        
        if($this->debug)
        {
            debug_print($this->query);
        }
                
        return $query;
    }
    
    function query($query)
    {
        if($this->debug){ echo '<pre>QUERY:</pre>'; }
        
        $this->affected_rows = null;
        $this->insert_id = null;
        $this->num_rows = null;
        
        $query = $this->eval_query($query);
        
        $result = new mssqli_result($this->pdo,$query,$this->debug);
        
        return $result;
    }
        
    function prepare($query)
    {
        if($this->debug){ echo '<pre>PREPARE:</pre>'; }
        
        $this->affected_rows = null;
        $this->insert_id = null;
        $this->num_rows = null;
        
        $query = $this->eval_query($query);
                        
        $this->stmt = $this->pdo->prepare($query, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));

        if($this->pdo->errorCode()!=00000)
        {
            $error = $this->pdo->errorInfo();
            $this->error = $error[2];
        }
                
        return new mssqli_stmt($this->pdo,$this->stmt,$this->query,$this->debug);
    }
        
    function __destruct()
    {
        //$this->pdo = null;
    }
}

class mssqli_stmt
{
    private $debug;
    private $pdo;
    private $stmt;
    private $query;
    private $results;
    
    // public variables
    public $affected_rows;
    public $insert_id;
    public $num_rows;
    public $error;

    function __construct($pdo,$stmt,$query,$debug)
    {
        $this->debug = $debug;
        $this->pdo = $pdo;
        $this->stmt = $stmt;
        $this->query = $query;
        
        if($this->debug)
        {
            debug_print($this);
        }
    }
    
    function bind_param()
    {
        if($this->debug){ echo '<pre>BIND_PARAM:</pre>'; }
        
        $args = func_get_args();
       
        $types = str_split(array_shift($args),1);
        $set = $this->set;
        $set_vals = $this->set_vals;
                
        if($this->debug)
        {
            debug_print($set);
            debug_print($set_vals);
            debug_print($args);
            debug_print($types);
        }
        
        if(count($args)!=count($types))
        {
            die('types does not match number of arguments');
        }
        /*elseif(count($set)!=count($args))
        {
            die('number of fields does not match number of arguments');
        }*/
        else
        {
            foreach($args as $key => $val)
            {
                switch($types[$key])
                {
                    case 's':
                        $this->stmt->bindValue(($key+1),$val,PDO::PARAM_STR);
                        break;
                    case 'i':
                        $this->stmt->bindValue(($key+1),$val,PDO::PARAM_INT);
                        break;
                }
            } 
        }
    }
    
    function execute()
    {
        if($this->debug){ echo '<pre>EXECUTE:</pre>'; }
        
        $this->stmt->execute();
        
        if($this->stmt->errorCode())
        {
            $error = $this->stmt->errorInfo();
            
            $this->error = $error[2];
            
            if($this->debug){ echo $this->error; }
        }
        
        $this->affected_rows = $this->stmt->rowCount();
        
        if($this->query->type=='insert')
        {
            $get_last_insert = $GLOBALS['mssqli']->query("SELECT id FROM " . $this->query->table . " ORDER BY id DESC LIMIT 1");
            $last_insert = $get_last_insert->fetch_array();
            
            $this->insert_id = $last_insert['id'];
        }
    }
    
    /* COULD POSSIBLY BE OPTIMISED USING A REFERENCE AND func_get_args() */
    function bind_result(&$param0 = null, &$param1 = null, &$param2 = null, &$param3 = null, &$param4 = null, &$param5 = null, &$param6 = null, &$param7 = null, &$param8 = null, &$param9 = null, &$param10 = null, &$param11 = null, &$param12 = null, &$param13 = null, &$param14 = null, &$param15 = null, &$param16 = null, &$param17 = null, &$param18 = null, &$param19 = null, &$param20 = null)
    {
         $param_num = func_num_args();
                  
         for($i = 0; $i < $param_num; $i++)
         {
             $name = 'param'.$i;
             $this->stmt->bindColumn($i+1,$$name);
         }
    }
    
    function store_result()
    {  
        if($this->debug){ echo '<pre>STORE_RESULT:</pre>'; }
        
        $this->results = $this->stmt->fetchAll();
                
        $this->stmt->execute();
            
        $this->num_rows = count($this->results);
    }
    
    function fetch()
    {
        if($this->debug){ echo '<pre>FETCH:</pre>'; }
        
        $this->results = $this->stmt->fetch(PDO::FETCH_ASSOC);
                
        return $this->results;
    }
        
    function num_rows()
    {
        return $this->num_rows;
    }
                    
    function close()
    {
        if($this->debug){ echo '<pre>CLOSE:</pre>'; }
        
        $this->stmt->closeCursor;
    }
    
    function __destruct()
    {
        if($this->debug){ echo '<pre>DESTROY:</pre>'; }
    }
}

class mssqli_result implements Iterator
{
    private $debug;
    private $pdo;
    private $query;
    private $result;
    private $results;
    
    private $position = 0;
    
    // public variables
    public $affected_rows;
    public $insert_id;
    public $num_rows;
    public $error;

    function __construct($pdo,$query,$debug)
    {
        $this->position = 0;
        
        $this->debug = $debug;
        $this->pdo = $pdo;
        $this->query = $query;
        
        if($query_results = $this->pdo->query($this->query))
        {
            foreach($query_results as $result)
            {
                $results[] = $result;
            }
        }
        
        $this->results = $results;
        $this->num_rows = count($results);
    }
    
    function rewind() {
        $this->position = 0;
    }

    function current() {
        return $this->results[$this->position];
    }

    function key() {
        return $this->position;
    }

    function next() {
        ++$this->position;
    }

    function valid() {
        return isset($this->results[$this->position]);
    }
    
    function fetch_array()
    {
        if($this->valid())
        {
            $output = $this->current();
            $this->next();
            
            return $output;
        }
        else
        {
            return false;
        }
    }
    
    function free_result()
    {
        $this->results = null;
    }
}