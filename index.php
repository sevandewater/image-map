<?php
require_once 'config.php';

class page
{
	//base64 encoded dir path
	protected $project = '';
	
	protected $id = false;
	
	protected $action = '';
	
	protected $data = null;
	
	protected $classes = array();
	
	protected $dir = '';
	
	public function process()
	{
		if(!$this->parse()) {
			$this->sendError(400, 'unable to parse request');
		}
		
		if(!$this->dbInit()) {
			$this->sendError(500, 'unable to connect to database');
		}
		
		switch($this->action) {
			case 'load':
			case 'next':
				$this->getImageData();
				break;
			case 'project':
				$this->updateProject();
				break;
			case 'update':
				$this->putImageData();
				break;
			case 'categories':
				$this->getCategories();
				break;
			default:
			    $this->sendError(400, 'Bad request');
		}
		
		return true;
	}
	
	protected function sendError($errorCode, $description)
	{
		header("HTTP/1.1 $errorCode $description");
		
		die(json_encode(array('code'=>$errorCode,'description'=>$description)));
	}
	
	protected function sendJson($data)
	{
		header('Content-Type: application/json');
		
		die(json_encode($data));
	}
	
	protected function parse()
	{
		if(empty($_REQUEST['p'])) {
			$this->sendError(400, 'Project does not exist');
		}
		
		$this->project = $_REQUEST['p'];
		$this->dir = base64_decode($_REQUEST['p']);
		
		if(!file_exists($this->dir)) {
			$this->sendError(404, 'Project not found');
		}
		
		if(!empty($_REQUEST['a'])) $this->action = $_REQUEST['a'];
		
		$data = file_get_contents('php://input', true);
		
		$this->log(4, 'data from initial payload (parsing) - '.print_r($data, true));
		
		if($data) {
			$this->data = new struct_item_image($data);
		}
		$this->log(4, 'data after parseing- '.print_r($this->data, true));
		
		return true;
	}
	
	protected function updateProject()
	{
		
		//todo load dir into database, or update if new exist (ignore what doesn't exist)
		
		$files = array();
		
		if(!$this->getFilesFromDir($files, '')) {
			$this->sendError(500, 'Error finding files');
		}
		
		if(0 >= count($files)) {
			$this->sendError(404, 'Did not find files to process');
		}
		
		$total = count($files);
		$new = 0;
		
		foreach($files as $file) {
			if(!$this->storeImage($added, $file)) {
				$this->sendError(500, 'Unable to store image in database');
			}
			
			if($added) $new += 1;
		}		
		
		$this->sendJson(array('total' => $total, 'added' => $new));
	}
	
	protected function getCategories()
	{
		$cat_file = $this->dir.DIRECTORY_SEPARATOR.'categories.txt';
		
		if(!file_exists($cat_file)) {
			$this->sendError(404, 'Categories not found');
		}
		
		$data = explode("\n", file_get_contents($cat_file));
		
		$categories = array();
		
		foreach($data as $line) {
			list($key,$value) = explode("\t", $line);
			$categories[$key] = $value;
		}
		
		//$categories = array(1 => 'test 1', 2 => 'test 2');
		
		$this->sendJson($categories);
	}
	
	protected function getImageData($id = false)
	{
		if(!$id) $id = $this->id;
		
		if(!$id) {
			$sql = 'select * from '.DB_TABLE_IMAGE.' where processed = 0 and project="'.$this->project.'" limit 1';
		} else {
			$sql = 'select * from '.DB_TABLE_IMAGE.' where project="'.$this->project.'" and id='.$this->data->quote($id);
		}
		//die($sql);
		//$this->sendError(501, $sql);
		
		if(!($data = $this->dbQuery($sql))) {
			$this->sendError(500, 'Failed to fetch record');
		}
		
		if(empty($data[0]) || !is_array($data[0])) {
			$this->sendError(404, 'no file found');
		}
		
		$data = $data[0];
		
		$boxes = array();
		if(!$this->getImageBoxData($boxes, $data['id'])) {
			$this->sendError(500, 'Failed to get image box data');
		}
		
		$data['boxes'] = $boxes;
		
		$decoded_full_file = $this->dir.base64_decode($data['file_name']);
		
		if(!file_exists($decoded_full_file)) {
			$this->sendError(400, "$file does not exist");
		}
		
		$data['image'] = base64_encode(file_get_contents($decoded_full_file));
		//print_r($data);
		
		$data = new struct_item_image($data);
		//print_r($data);exit;
		
		$this->sendJson($data);
	}
	
	protected function putImageData()
	{
		$this->log(4, 'put image input'.print_r($this->data, true));
		
		if(!is_a($this->data, 'struct_item_image') || !is_numeric($this->data->id) || !$this->data->id) {
			$this->sendError(400, 'data not provided or invalid');
		}
		
		if(!$this->clearImageBoxData($this->data->id)) {
			$this->sendError(500, 'unable to clear previous data');
		}
		
		foreach($this->data->boxes as $box) {
			if(!$this->putImageBoxData($box)) {
				return false;
			}
		}
		
		$sql = "update ". DB_TABLE_IMAGE ." set processed=1 where id=".$this->db->quote($this->data->id);
		
		if(!$this->dbExec($sql)) {
			$this->sendError(500, 'could not update processing status');
		}
		
		$this->sendJson(array('code'=>202,'id'=>$this->data->id));
	}
	
	protected function getImageBoxData(&$boxes, $image_id)
	{
		$sql = 'select * from '.DB_TABLE_IMAGE_BOX.' where image_id='.$this->db->quote($image_id);
		
		foreach($this->dbQuery($sql) as $box) {
			$boxes[] = $box;
		}
		
		return true;
	}
	
	protected function clearImageBoxData($id)
	{
		$sql = 'delete from '. DB_TABLE_IMAGE_BOX .' where image_id = '. $this->db->quote($id);
		//$this->sendError(501, $sql);
		if(false === $this->dbExec($sql)) {
			return false;
		}
		
		return true;
	}
	
	protected function putImageBoxData($item)
	{
		//todo parse json and store x,y,h,w,class in db
		if(!is_a($item, 'struct_item_image_box')) {
			$this->sendError(400, 'data not provided or invalid');
		}
		
		if(!($db_data = $item->toArray(array('image_id','class','x','y','height','width','clipped','difficult')))) {
			$this->sendError(500, 'problem saving box data');
		}
		
		$sql = $this->dbBuildInsert(DB_TABLE_IMAGE_BOX, $db_data);
		
		if(!$this->dbExec($sql)) {
			$this-> sendError(500, 'Unable to update image data');
		}
		
		return true;
	}
	
	protected function storeImage(&$added, struct_item_image $file)
	{
		$added = false;
		$count = 0;
		$safe_file_name = $this->db->quote($file->file_name);
		$safe_project = $this->db->quote($this->project);
		if(!$this->dbRecordCount($count, DB_TABLE_IMAGE, "file_name={$safe_file_name} and project={$safe_project}")) {
			$this->log(1, 'failed to query record exists');
			return false;
		}
		
		if($count) return true;
		
		$data = array(
			'project'	=> $this->project,
			'content_type' => $file->content_type,
			'file_name' => $file->file_name,
			'height' => $file->height,
			'width' => $file->width
		);
		
		$sql = $this->dbBuildInsert(DB_TABLE_IMAGE, $data);
		
		if(!$this->dbExec($sql)) {
			return false;
		}
		
		$added = true;
		
		return true;
	}
	
	protected function dbInit($dsn = DB_DSN, $user = DB_USER, $password = DB_PASS)
	{
		try {
			$this->db = new PDO($dsn, $user, $password);
		} catch (PDOException $e) {
			print_r($e);exit;
			$this->log(1, 'Failed to initialize database with message '. $e->getMessage());
			$this->log(3, 'database init failed, exception: '.print_r($e, true));
			return false;
		}		
		
		return true;
	}
	
	protected function dbQuery($sql, $options=false)
	{
		if(false == $options) $options = PDO::FETCH_ASSOC; 
		if(!($result = $this->db->query($sql, $options))) {
			$this->log(1, "DB Error in dbQuery $sql ".print_r($this->db->errorInfo(), true));
			return false;
		}
		
		return $result->fetchAll();
	}
	
	protected function dbExec($sql)
	{
		if(false === ($affected_rows = $this->db->exec($sql))) {
			$this->log(1, "DB Error in dbExec $sql ".print_r($this->db->errorInfo(), true));
			return false;
		}
		
		return $affected_rows;
	}
	
	protected function dbBuildInsert($table, $data)
	{
		$fields = '';
		$values = '';
		
		foreach($data as $key => $value) {
			if($fields) $fields .= ',';
			if($values) $values .= ',';
			$fields .= $key;
			$values .= $this->db->quote($value);
		}
		
		return "insert into $table ($fields) values($values)";
	}
	
	protected function dbBuildUpdate($table, $data, $where)
	{
		$values = '';
		
		foreach($data as $key => $value) {
			if($values) $values .= ',';
			$values .= "$key=".$this->db->quote($value);
		}
		
		return "update $table set $values where $where";
	}
	
	protected function dbRecordCount(&$count, $table, $where)
	{
		if($where) $where = "where $where";
		$sql = "select count(*) as ncount from $table $where";
		//die($sql);
		if(!($row = $this->dbQuery($sql))) {
			return false;
		}
		
		$count = $row[0]['ncount'];
		
		return true;
	}
	
	protected function getFilesFromDir(array &$files, $dir, $recurse = true)
	{
		$full_dir = realpath($this->dir . DIRECTORY_SEPARATOR . $dir);
		$dir_files = scandir($full_dir);
		foreach($dir_files as $file) {
			//if(count($files)>50)return true;
			if(in_array($file, array('.','..','categories.txt'))) continue;
			
			$file_name = ($dir) ? $dir . DIRECTORY_SEPARATOR . $file : $file;
			$full_file = realpath($full_dir.DIRECTORY_SEPARATOR.$file);
			
			if(is_dir($full_file) && $recurse) {
				if(!$this->getFilesFromDir($files, $file, true)) {
					return false;
				}
			} else {
				$img = getimagesize($full_file);
				//print_r($img);exit;
				$data = array(
					'width'	=> $img[0],
					'height'	=> $img[1],
					'content_type'	=> $img['mime'],
					'file_name'	=> base64_encode($file_name)
				);
				$files[] = new struct_item_image($data);
			}
		}
		
		return true;
	}	
	
	protected function log($level, $message)
	{
		if(LOG_LEVEL < $level) return true;
		
		$message = date('Y-m-d H:js') . " - level: $level - $message\n";
		file_put_contents(LOG_FILE, $message, FILE_APPEND);
		
		return true;
	}		
}

class struct_item_image
{
	public $id = 0;
	public $project = '';
	public $file_name = '';
	public $content_type = '';
	public $height = 0;
	public $width = 0;
	public $processed = false;
	public $image = '';
	public $boxes = array();
	
	public function __construct($data)
	{
		if(is_string($data)) {
			$this->fromJson($data);
		} elseif(is_array($data)) {
			$this->fromArray($data);
		} elseif(is_object($data)) {
			$this->fromObject($data);
		} else {
			throw new Exception('item image data is not an array or a string'.print_r($data,true));
		}
	}
	
	public function fromJSON(string $data)
	{
		$this->log(4, 'data struct_item-image::fromJSON - '.$data);
		
		$this->fromObject(json_decode($data));
	}
	
	public function fromObject($obj)
	{		
		$this->log(4, 'data struct_item-image::fromObject - '.print_r($obj, true));
		
		$this->id = (!empty($obj->id)) ? $obj->id : 0;
		$this->project = (!empty($obj->project)) ? $obj->project : 0;
		$this->content_type = (!empty($obj->content_type)) ? $obj->content_type : 0;
		$this->file_name = (!empty($obj->file_name)) ? $obj->file_name : 0;
		$this->height = (!empty($obj->height)) ? $obj->height : 0;
		$this->width = (!empty($obj->width)) ? $obj->width : 0;
		$this->processed = (!empty($obj->processed)) ? $obj->processed : 0;
		$this->image = (!empty($obj->image)) ? $obj->image : '';
		
		if(!empty($obj->boxes) && is_array($obj->boxes)) {
			foreach($obj->boxes as $box) {
				$box->image_id = $this->id;
				$this->boxes[] = new struct_item_image_box($box);
			}
		}		
	}
	
	public function fromArray(array $data)
	{		
		$this->log(4, 'data struct_item-image::fromArray - '.print_r($data, true));
		
		$this->id = (!empty($data['id'])) ? $data['id'] : 0;
		$this->project = (!empty($data['project'])) ? $data['project'] : 0;
		$this->content_type = (!empty($data['content_type'])) ? $data['content_type'] : 0;
		$this->file_name = (!empty($data['file_name'])) ? $data['file_name'] : 0;
		$this->width = (!empty($data['width'])) ? $data['width'] : 0;
		$this->height = (!empty($data['height'])) ? $data['height'] : 0;
		$this->processed = (!empty($data['processed'])) ? $data['processed'] : 0;
		$this->image = (!empty($data['image'])) ? $data['image'] : '';
		
		if(!empty($data['boxes']) && is_array($data['boxes'])) {
			foreach($data['boxes'] as $box) {
				$box->image_id = $this->id;
				$this->boxes[] = new struct_item_image_box($box);
			}
		}
	}
	
	public function toJson()
	{
		return json_encode($this);
	}
	
	public function toArray($fields = array('id','project','content_type','file_name','class','width','height','processed','image','boxes'))
	{
		$data = array();
		
		foreach($fields as $field) {
			if(!property_exists('struct_item_image', $field)) {
				$this->log(1, "Error, field $field is not valid");
				return false;
			}
			
			$data[$field] = $this->$field;
		}
		
		return $data;
	}	
	
	protected function log($level, $message)
	{
		if(LOG_LEVEL < $level) return true;
		
		$message = date('Y-m-d H:js') . " - level: $level - $message\n";
		file_put_contents(LOG_FILE, $message, FILE_APPEND);
		
		return true;
	}
}

class struct_item_image_box
{
	public $id = 0;
	public $image_id = '';
	public $class = 0;
	public $height = 0;
	public $width = 0;
	public $x = 0;
	public $y = 0;
	public $clipped = 0;
	public $difficult = 0;
	
	public function __construct($data)
	{
		if(is_string($data)) {
			$this->fromJson($data);
		} elseif(is_array($data)) {
			$this->fromArray($data);
		} elseif(is_object($data)) {
			$this->fromObject($data);
		} else {
			throw new Exception('image_box data is not an array or a string'.print_r($data,true));
		}
	}
	
	public function fromJSON(string $data)
	{
		$this->log(4, 'data struct_item-image_boxes::fromJSON - '.print_r($data, true));
		
		$this->fromObject(json_decode($data));
		
	}
	
	public function fromObject($obj)
	{
		$this->log(4, 'data struct_item-image_boxes::fromObject - '.print_r($obj, true));
		
		$this->id = (!empty($obj->id)) ? $obj->id : 0;
		$this->image_id = (!empty($obj->image_id)) ? $obj->image_id : 0;
		$this->class = (!empty($obj->category)) ? $obj->category : 0;
		$this->x = (!empty($obj->x)) ? $obj->x : 0;
		$this->y = (!empty($obj->y)) ? $obj->y : 0;
		$this->height = (!empty($obj->height)) ? $obj->height : 0;
		$this->width = (!empty($obj->width)) ? $obj->width : 0;
		$this->clipped = (!empty($obj->clipped)) ? 1 : 0;
		$this->difficult = (!empty($obj->difficult)) ? 1 : 0;
	}
	
	public function fromArray(array $data)
	{		
		$this->log(4, 'data struct_item-image_boxes::fromArray - '.print_r($data, true));
		
		$this->id = (!empty($data['id'])) ? $data['id'] : 0;
		$this->file_id = (!empty($data['file_id'])) ? $data['file_id'] : 0;
		$this->class = (!empty($data['class'])) ? $data['class'] : 0;
		$this->x = (!empty($data['x'])) ? $data['x'] : 0;
		$this->y = (!empty($data['y'])) ? $data['y'] : 0;
		$this->height = (!empty($data['height'])) ? $data['height'] : 0;
		$this->width = (!empty($data['width'])) ? $data['width'] : 0;
		$this->clipped = (!empty($data['clipped'])) ? 1 : 0;
		$this->difficult = (!empty($data['difficult'])) ? 1 : 0;
	}
	
	public function toJson()
	{
		return json_encode($this);
	}
	
	public function toArray($fields = array('id','file_id','class','x','y','height','width'))
	{
		$data = array();
		
		foreach($fields as $field) {
			if(!property_exists('struct_item_image_box', $field)) {
				$this->log(1, "Error, field $field is not valid");
				return false;
			}
			
			$data[$field] = $this->$field;
		}
		
		return $data;
	}	
	
	protected function log($level, $message)
	{
		if(LOG_LEVEL < $level) return true;
		
		$message = date('Y-m-d H:js') . " - level: $level - $message\n";
		file_put_contents(LOG_FILE, $message, FILE_APPEND);
		
		return true;
	}
}

$page = new page();
$page->process();
