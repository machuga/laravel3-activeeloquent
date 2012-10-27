<?php
namespace ActiveEloquent;

class DateTime extends \DateTime
{
	public function __toString()
	{
		return $this->format('Y-m-d h:m a');
	}
}
