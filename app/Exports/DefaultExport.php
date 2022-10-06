<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;

class DefaultExport implements FromArray
{
	protected $items;

	public function __construct($items)
	{
		$this->items = $items;
	}

	public function array(): array
	{
		return $this->items;
	}

}
