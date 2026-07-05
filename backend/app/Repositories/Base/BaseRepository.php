<?php

namespace App\Repositories\Base;

use Illuminate\Support\Facades\DB;

abstract class BaseRepository
{
    protected string $table;

    public function find(int $id)
    {
        return DB::table($this->table)
            ->where('id', $id)
            ->first();
    }

    public function all()
    {
        return DB::table($this->table)->get();
    }

    public function create(array $data)
    {
        return DB::table($this->table)
            ->insertGetId($data);
    }

    public function update(int $id, array $data)
    {
        return DB::table($this->table)
            ->where('id', $id)
            ->update($data);
    }

    public function delete(int $id)
    {
        return DB::table($this->table)
            ->where('id', $id)
            ->delete();
    }
}