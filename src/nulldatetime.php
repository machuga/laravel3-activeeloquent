<?php
namespace ActiveEloquent;

class NullDateTime extends \DateTime
{
	public function __toString()
	{
		return null;
	}

	public function format($format)
	{
		return null;
	}
}
