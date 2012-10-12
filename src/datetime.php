<?php
namespace ActiveEloquent;

class DateTime extends \DateTime
{
	public function __toString()
	{
		return $this->format('Y-m-d h:m a');
	}

	public function setDateFromFormat($string)
	{
		$date = new DateTime($string);
		$exploded = explode('-', $date->format('Y-m-d'));
		return call_user_func_array([$this, 'setDate'], $exploded);
	}
}
