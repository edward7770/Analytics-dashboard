<?php

namespace App;

class Rule {
	
	public $id, $date, $uses, $action, $bantime, $trigger, $threshold, $clientArr, $clientStr, $global, $gTrigger, $gThreshold, $whitelisted;
	
	// Create new rule
	private function create($data) {
		if(isset($data['id']) && ctype_digit($data['id'])){
			$this->id = $data['id'];
		}	

		$this->date = date("Y-m-d H:i:s",time());
    $this->uses = 0;
    
    if(empty($data['threshold'])) {
      return false;
    }

    if($data['action'] === "ban" && empty($data['bantime'])) {
      return false;
    }

		if(!in_array($data['action'], array('ban','disconnect','notice',), true)){
			return false;
		}
		$this->action = $data['action'];
		
		if(isset($data['bantime']) && ctype_digit($data['bantime'])){
			$this->bantime = $data['bantime'];
		}
		
		if(!in_array($data['trigger'], array('client','traffic','trafficin','trafficout','ping'), true)){
			return false;
		}
		$this->trigger = $data['trigger'];
		
		if(isset($data['threshold']) && ctype_digit($data['threshold'])){
			$this->threshold = $data['threshold'];
		}

		if(isset($data['clientselection'])){
			foreach($data['clientselection'] as $c){
				if(preg_match("/^\s{0,1}SPV|\s{0,1}Unlimited|\s{0,1}ABC|\s{0,1}bcoin$/", $c)){
				$this->clientArr[] = $c;  
				$this->clientStr .= $c.", ";
				}
			}
			$this->clientStr  = substr($this->clientStr , 0, -2);  
    }
    
    if(isset($data['whitelisted'])){
			$this->whitelisted = true;
		} else {
      $this->whitelisted = false;
    }
		
		if(isset($data['global']) && $data['global'] !== "on"){
			return false;
    }
  
		
		if(!isset($data['global'])){
			$this->global = false;
			return true;
		}	   
		$this->global = true;
		
		if(!in_array($data['gTrigger'], array('traffic','peercount'), true)){
			return false;
		}
		$this->gTrigger = $data['gTrigger'];
		
		if(!ctype_digit($data['gThreshold'])){
			return false;
		}	   
		$this->gThreshold = $data['gThreshold'];
			
		return true;	  
	}

	// Save a new rule
	public function save(array $data) {
		$rules = self::getRules();
		if(!$this->create($data)){
			return false;
		}
		// Check if rule exists or if new
		if(empty($this->id)){ 
			end($rules);
			$this->id = key($rules)+1;
		}
		$rules[$this->id] = $this;
		
		file_put_contents('data/rules.inc',serialize($rules)); 
		return true;
	}

// Stactic functions
	
// Runs all rules
	/**
	 * @return bool
	 */
	public static function run() {
		global $bitcoind; 

		$data = self::getData();
		
		if(empty($data['peers'])){
			return true;
		}
		
		$logging = "";
		
		// Checks every rule
		foreach($data['rules'] as &$rule) {
			// Checks if global trigger set and if triggered
			if($rule->global) {
				$gResult = false;
				switch($rule->gTrigger) {
					case "traffic":
						if($data['global']['traffic'] > $rule->gThreshold) $gResult = true;
						break;
					case "peercount":
						if($data['global']['connections'] > $rule->gThreshold) $gResult = true;
						break;
				}
				if(!$gResult) {
					continue;
				}
			}		   
			// If global trigger is active or not, every peer is checked
			foreach($data['peers'] as $peer) {
        if(!$rule->whitelisted && $peer->whitelisted) continue;
				$result = false;
				switch($rule->trigger) {
					case "client":
						foreach($rule->clientArr as $client){
							if($client == "SPV" AND $peer->spv){
								$result = true;
								break;
							}elseif(preg_match("/".$client."/i", $peer->orgClient)) {
								$result = true;
								break;
							}
						}
						break;	 
					case "traffic":
						if($peer->traffic > $rule->threshold) $result = true;
						break;
					case "trafficin":
						if($peer->trafficIn > $rule->threshold) $result = true;
						break;
					case "trafficout":
						if($peer->trafficOut > $rule->threshold) $result = true;
						break;
					case "ping":
						if($peer->ping > $rule->threshold) $result = true;
						break;				 
				}
				
				if(!$result) {
					continue;
				}
				
				$logTime = date("Y-m-d H:i:s",time());

				switch($rule->action) {
					case "ban":
						try{
							$msg1 = $bitcoind->setban($peer->ip, "add", intval($rule->bantime));
							$logging .= $logTime." - ID ".$rule->id.": Banned (".$rule->trigger."): ".$peer->ip." (".$peer->client.") for ".$rule->bantime." s\r\n";
						}catch(\Exception $e) {
							$logging .= $logTime." - ID ".$rule->id.": Error banning ".$peer->ip." (".$peer->client.") for ".$rule->bantime." s\r\n";
						}						

						break;
					case "disconnect":
						try{
							$bitcoind->disconnectnode($peer->ip);
							$logging .= $logTime." - ID ".$rule->id.": Disconnected (".$rule->trigger."): ".$peer->ip." (".$peer->client.")\r\n";
						}catch(\Exception $e) {
							$logging .= $logTime." - ID ".$rule->id.": Error disconnecting ".$peer->ip." (".$peer->client.")\r\n";
						}
						break;
					case "notice":
						$logging .= $logTime." - ID ".$rule->id.": Notice (".$rule->trigger."): ".$peer->ip." (".$peer->client.")\r\n";
						break;
				}
				$rule->uses++;
			}
		}

		if (file_exists('data/rules.log')){
			$logging .= file_get_contents('data/rules.log');
		}
		if(!empty($logging)){
			file_put_contents('data/rules.log', $logging);
		}
		file_put_contents('data/rules.inc',serialize($data['rules']));
	}
	
	// Get information needed for rule run
	private static function getData(){
		
		$node = new Node();
		$data['peers'] = getPeerData(false)['peers'];
		$data['rules'] = self::getRules();
		$data['global']['connections'] = $node->toConn;
		$data['global']['traffic'] = $node->tTotal;
		
		return $data;
	}

	// Delete a single rule/all
	public static function deleteByID(int $id) { 
		$rules = self::getRules(); 
		if(array_key_exists($id, $rules)) {
			unset($rules[$id]);
			file_put_contents('data/rules.inc', serialize($rules)); 
			$result = true;
		}else{
			$result = false;
		}
		return $result;
	}

	// Delete a single rule/all
	public static function deleteAll() { 
		return unlink('data/rules.inc');
	}

	// Return a single rule
	public static function getByID(int $id) {
		$rules = self::getRules(); 
		if(array_key_exists($id, $rules)) {
			$rule = $rules[$id];
			return $rule;
		}		 
		return false;
	}

	// Return all rules
	public static function getRules() {
		if (file_exists('data/rules.inc')){
			$rules = unserialize(file_get_contents('data/rules.inc')); 
		}else{
			$rules = array();
		}	   
		return $rules;
	}

	// Resets the counter for rule uses
	public static function resetCounter(){
		$rules = self::getRules();
		foreach($rules as &$rule){
			$rule->uses = 0;
		}
		file_put_contents('data/rules.inc',serialize($rules));
		return true;
	}

	// Delete Logfile
	public static function deleteLogfile(){
		return unlink('data/rules.log');
	}
}
?>