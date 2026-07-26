<?php

namespace Modules\Admin\Repositories;

use Modules\Admin\Interfaces\IconRepositoryInterface;
use Modules\Admin\Models\Icon;

class IconRepository implements IconRepositoryInterface
{
    public function all()
    {
        return Icon::orderBy('created_at', 'desc')->get();
    }

    public function find(int $id)
    {
        return Icon::findOrFail($id);
    }

    public function create(array $data)
    {
        return Icon::create($data);
    }

    public function update(int $id, array $data)
    {
        $icon = $this->find($id);
        $icon->update($data);

        return $icon;
    }

    public function delete(int $id)
    {
        $icon = $this->find($id);

        return $icon->delete();
    }

    public function paginate(int $perPage = 15)
    {
        return Icon::orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function findByType(string $type)
    {
        return Icon::where('type', $type)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function paginateByType(int $perPage, string $type)
    {
        return Icon::where('type', $type)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function findByTypeExcludingIds(string $type, array $excludeIds)
    {
        $query = Icon::where('type', $type)->orderBy('created_at', 'desc');

        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        return $query->get();
    }

    public function findExcludingIds(array $excludeIds)
    {
        $query = Icon::orderBy('created_at', 'desc');

        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        return $query->get();
    }
}
