<?php

require ('../config.php');

$get = GETPOST('get','alpha');
$put = GETPOST('put','alpha');
	
_put($db, $put);
_get($db, $get);

function _get(&$db, $case) {
	switch ($case) {
		case 'tasks' :
			
			print json_encode(_tasks($db, (int)$_REQUEST['id_project'], $_REQUEST['status'], GETPOST('fk_user') ));

			break;
		case 'task' :
			
			print json_encode(_task($db, (int)GETPOST('id')));

			break;
			
		case 'velocity':
			
			print json_encode(_velocity($db, (int)$_REQUEST['id_project']));
			
			break;
	}

}

function _put(&$db, $case) {
	switch ($case) {
		case 'task' :
			
			print json_encode(_task($db, (int)GETPOST('id'), $_REQUEST));
			
			break;
			
		case 'sort-task' :
			
			_sort_task($db, $_REQUEST['TTaskID']);
			
			break;
		case 'reset-date-task':
			
			_reset_date_task($db,(int)GETPOST('id_project'), (float)GETPOST('velocity') * 3600);
			
			break;

	}

}

function _velocity(&$db, $id_project) {
global $langs;
	
	$Tab=array();
	
	$velocity = scrum_getVelocity($db, $id_project);
	$Tab['velocity'] = $velocity;
	$Tab['current'] = convertSecondToTime($velocity).$langs->trans('HoursPerDay');
	
	if( (float)DOL_VERSION <= 3.4 ) {
		// ne peut pas gérér la résolution car pas de temps plannifié			
	}
	else {
		
		if($velocity>0) {
			
			$time = time();
			$res=$db->query("SELECT SUM(planned_workload-duration_effective) as duration 
				FROM ".MAIN_DB_PREFIX."projet_task 
				WHERE fk_projet=".$id_project." AND progress>0 AND progress<100");
			if($obj=$db->fetch_object($res)) {
				//time rest in second
				$time_end_inprogress = $time + $obj->duration / $velocity * 86400;
			}
			
			if($time_end_inprogress<$time)$time_end_inprogress = $time;
			
			$res=$db->query("SELECT SUM(planned_workload-duration_effective) as duration 
				FROM ".MAIN_DB_PREFIX."projet_task 
				WHERE fk_projet=".$id_project." AND progress=0");
			if($obj=$db->fetch_object($res)) {
				//time rest in second
				$time_end_todo = $time_end_inprogress + $obj->duration / $velocity * 86400;
			}
			
			if($time_end_todo<$time)$time_end_todo = $time;
			
			if($time_end_todo>$time_end_inprogress) $Tab['todo']=', '.$langs->trans('EndedThe').' '.date('d/m/Y', $time_end_todo);
			$Tab['inprogress']=', '.$langs->trans('EndedThe').' '.date('d/m/Y', $time_end_inprogress);
			
			
		}
		
		
		
	}
	
	return $Tab;
	
}

function _as_array(&$object, $recursif=false) {
global $langs;
	$Tab=array();
	
		foreach ($object as $key => $value) {
				
			if(is_object($value) || is_array($value)) {
				if($recursif) $Tab[$key] = _as_array($recursif, $value);
				else $Tab[$key] = $value;
			}
			else if(strpos($key,'date_')===0){
				
				$Tab['time_'.$key] = $value;	
				
				if(empty($value))$Tab[$key] = '0000-00-00 00:00:00';
				else $Tab[$key] = date('Y-m-d H:i:s',$value);
			}
			else{
				$Tab[$key]=$value;
			}
		}
		return $Tab;
	
}

function _sort_task(&$db, $TTask) {
	global $user;
	
	foreach($TTask as $rank=>$id) {
		$task=new Task($db);
		$task->fetch($id);
		$task->rang = $rank;
		$task->update($user);
	}
	
}
function _set_values(&$object, $values) {
	
	foreach($values as $k=>$v) {
		
		if(isset($object->{$k})) {
			
			$object->{$k} = $v;
			
		}
		
	}
	
}
function _task(&$db, $id_task, $values=array()) {
global $user, $langs,$conf;

	$task=new Task($db);
	if($id_task) $task->fetch($id_task);
	
	if(!empty($values)){
		_set_values($task, $values);
	
		if($values['status']=='inprogress') {
			if($task->progress==0)$task->progress = 5;
			else if($task->progress==100)$task->progress = 95;
		}
		else if($values['status']=='finish') {
			$task->progress = 100;
		}	
		else if($values['status']=='todo') {
			$task->progress = 0;
		}	
	
		$task->status = $values['status'];
		
		$task->update($user);
		
		$db->query("UPDATE ".MAIN_DB_PREFIX.$task->table_element." 
				SET story_k=".(int)$values['story_k']."
				,scrum_status='".$values['scrum_status']."'
			WHERE rowid=".$task->id);
	}
	
	$task->date_delivery = 0;
	if($task->date_end >0 && $task->planned_workload>0) {
		
		$velocity = scrum_getVelocity($db, $task->fk_project);
		$task->date_delivery = _get_delivery_date_with_velocity($db, $task, $velocity);
		
	}
	
	$dayInSecond = 86400;
	if($conf->global->TIMESHEET_WORKING_HOUR_PER_DAY){
		$dayInSecond = 60*60*$conf->global->TIMESHEET_WORKING_HOUR_PER_DAY;
	}
	
	$task->aff_time = convertSecondToTime($task->duration_effective,'all',$dayInSecond);
	$task->aff_planned_workload = convertSecondToTime($task->planned_workload,'all',$dayInSecond);

	$task->long_description.='';
	if($task->date_start>0) $task->long_description .= $langs->trans('TaskDateStart').' : '.dol_print_date($task->date_start).'<br />';
	if($task->date_end>0) $task->long_description .= $langs->trans('TaskDateEnd').' : '.dol_print_date($task->date_end).'<br />';
	if($task->date_delivery>0 && $task->date_delivery>$task->date_end) $task->long_description .= $langs->trans('TaskDateShouldDelivery').' : '.dol_print_date($task->date_delivery).'<br />';
	
	$task->long_description.=$task->description;

	if (!empty($conf->global->SCRUM_SHOW_LINKED_CONTACT)) _getTContact($task);
	
	return _as_array($task);
}

function _getTContact(&$task)
{
	global $db;

	$TInternalContact = $task->liste_contact(-1, 'internal');
	$TExternalContact = $task->liste_contact(-1, 'external');

	$task->internal_contacts = '';
	$task->external_contacts = '';
	if (!empty($TInternalContact))
	{
		dol_include_once('/user/class/user.class.php');
		$user = new User($db);
		foreach ($TInternalContact as &$row)
		{
			$user->id = $row['id'];
			$user->lastname = $row['lastname'];
			$user->firstname = $row['firstname'];
			$task->internal_contacts .= $user->getNomUrl(1).'&nbsp;';
		}
	}

	if (!empty($TExternalContact))
	{
		dol_include_once('/contact/class/contact.class.php');
		$contact = new Contact($db);
		foreach ($TExternalContact as &$row)
		{
			$contact->id = $row['id'];
			$contact->lastname = $row['lastname'];
			$contact->firstname = $row['firstname'];
			$task->external_contacts .= $contact->getNomUrl(1).'&nbsp;';
		}
	}
}

function _get_delivery_date_with_velocity(&$db, &$task, $velocity, $time=null) {
	
	if( (float)DOL_VERSION <= 3.4 || $velocity==0) {
		return 0;	
	
	}
	else {
		$rest = $task->planned_workload - $task->duration_effective; // nombre de seconde restante
		
		if(is_null($time)) {
			$time = time();
			if($time<$task->start_date)$time = $task->start_date;
		}
		
		$time += ( 86400 * $rest / $velocity  )  ;
	
		return $time;
		
	}
}	

function _reset_date_task(&$db, $id_project, $velocity) {
global $user;

	if($velocity==0) return false;

	$project=new Project($db);
	$project->fetch($id_project);


	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."projet_task 
	WHERE fk_projet=".$id_project." AND progress<100
	ORDER BY rang";

	$res = $db->query($sql);	
	
	$current_time = time();
	
	while($obj = $db->fetch_object($res)) {
		
		$task=new Task($db);
		$task->fetch($obj->rowid);
		
		if($task->progress==0)$task->date_start = $current_time;
		
		$task->date_end = _get_delivery_date_with_velocity($db, $task, $velocity, $current_time);
		
		$current_time = $task->date_end;
		
		$task->update($user);
		
	}
	
	$project->date_end = $current_time;
	$project->update($user);

}

function _tasks(&$db, $id_project, $status, $fk_user) {
	global $user,$conf;
	
	if (!empty($conf->global->SCRUM_FILTER_BY_USER_ENABLE))
	{
		if (empty($fk_user) && !$user->admin) $fk_user = $user->id;
	}
	else
	{
		$fk_user = 0;
	}
	
	
	
	$sql = 'SELECT DISTINCT pt.rowid, pt.story_k, pt.scrum_status, pt.rang FROM '.MAIN_DB_PREFIX.'projet_task pt';
	if ($fk_user > 0)
	{
		$sql.= ' INNER JOIN '.MAIN_DB_PREFIX.'element_contact ec ON (ec.element_id = pt.rowid)';
		$sql.= ' INNER JOIN '.MAIN_DB_PREFIX.'c_type_contact tc ON (tc.rowid = ec.fk_c_type_contact)';
	}
	
	if($status=='ideas') {
		$sql.= ' WHERE  progress = 0 AND datee IS NULL';
	}	
	else if($status=='todo') {
		$sql.= ' WHERE progress = 0';
	}
	else if($status=='inprogress') {
		$sql.= ' WHERE progress > 0 AND progress < 100';
	}
	else if($status=='finish') {
		$sql.= ' WHERE progress=100';
	}
	
	if($id_project > 0) $sql.= ' AND fk_projet='.$id_project;
	
	if ($fk_user > 0)
	{
		$sql.= ' AND tc.element = \'project_task\' AND ec.fk_socpeople = '.$fk_user;
	}
	
	$sql.= ' ORDER BY pt.rang';

	$res = $db->query($sql);	
		
		
	$TTask = array();
	while($obj = $db->fetch_object($res)) {
		$TTask[] = array_merge( _task($db, $obj->rowid) , array('status'=>$status,'story_k'=>$obj->story_k,'scrum_status'=>$obj->scrum_status));
	}
	
	return $TTask;
}
