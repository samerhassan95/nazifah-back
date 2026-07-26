<?php

namespace Modules\Admin\Interfaces;

interface IconRepositoryInterface
{
    public function all();

    public function find(int $id);

    public function create(array $data);

    public function update(int $id, array $data);

    public function delete(int $id);

    public function paginate(int $perPage = 15);

    public function findByType(string $type);

    public function paginateByType(int $perPage, string $type);

    public function findByTypeExcludingIds(string $type, array $excludeIds);

    public function findExcludingIds(array $excludeIds);
}
