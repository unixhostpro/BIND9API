<?php
namespace UnixHost\DNS\Validation\Rules;

class DomainValidRule extends \Rakit\Validation\Rule {
    protected $message = ':value is not valid domain';   
    
    public function check($value): bool
    {
    	if (substr($value, -1) == '.') return false;     
        return filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}

class DomainRule extends \Rakit\Validation\Rule {
    protected $message = ':value is not domain';   
    
    public function check($value): bool
    {  
        return filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}

class IPSubnetRule extends \Rakit\Validation\Rule {
    protected $message = ':value is not IP or SUBNET';   
    
    public function check($value): bool
    {        
    	$subvalue = explode('/', $value);
        return filter_var($subvalue['0'], FILTER_VALIDATE_IP) !== false;
    }
}

class RecordNameRule extends \Rakit\Validation\Rule {
    protected $message = ':value is not valid record name';   
    
    public function check($value): bool
    {        
		return \Badcow\DNS\Validator::resourceRecordName($value) !== false;
    }
}

class RecordTypeRule extends \Rakit\Validation\Rule {
    protected $message = ':value is not valid record type';   
    
    public function check($value): bool
    {   
		return \Badcow\DNS\Rdata\Types::isValid($value) !== false;
    }
}
