<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Survey extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'Id';

    protected $table = 'survey';

    public function questions(): array
    {
        $stored = array_values(array_filter([
            $this->Survey1,
            $this->Survey2,
            $this->Survey3,
            $this->Survey4,
            $this->Survey5,
        ]));
        if ($stored !== []) {
            return $stored;
        }

        $json = json_decode((string) $this->Json, true);
        if (! is_array($json)) {
            return [];
        }

        $found = [];
        foreach ($json['pages'] ?? [] as $page) {
            foreach ($page['elements'] ?? [] as $element) {
                $name = (string) ($element['name'] ?? '');
                if (preg_match('/^question([1-5])$/', $name, $match) && is_string($element['title'] ?? null) && trim($element['title']) !== '') {
                    $found[(int) $match[1]] = trim($element['title']);
                }
            }
        }
        ksort($found);

        return array_values($found);
    }
}
