<?php

namespace HenriqueBS0\Accessor;

use PDO;

abstract class Entity {

    protected string $table;
    protected array $attributes;
    protected string $primaryKey = '';

    private string $select = '';
    private string $where = '';
    private string $order = '';
    private int $limit = 0;

    public function insert(): void
    {
        $this->beforeInsert();

        $query = $this->getQueryInsert();
        $prepare = Connection::get()->prepare($query);
        $this->bindValues($prepare, $this->attributes);
        $prepare->execute();
        $this->{$this->primaryKey} = intval(Connection::get()->lastInsertId());

        $this->afterInsert();
    }

    public function update(): void
    {
        $this->beforeUpdate();

        $query = $this->getQueryUpdate();
        $prepare = Connection::get()->prepare($query);
        $this->bindValues($prepare, $this->attributes);
        $prepare->bindValue(':id', $this->{$this->primaryKey}, PDO::PARAM_INT);
        $prepare->execute();

        $this->afterUpdate();
    }

    public function delete(): void
    {
        $this->beforeDelete();

        $where = !$this->where ? "WHERE {$this->primaryKey} = :id" : "WHERE {$this->where}";

        $query = "DELETE FROM {$this->table} {$where}";
        $prepare = Connection::get()->prepare($query);
        $prepare->bindValue(':id', $this->{$this->primaryKey}, PDO::PARAM_INT);
        $prepare->execute();

        $this->where = '';

        $this->afterDelete();
    }

    public function select(): Entity
    {
        $this->select = "SELECT * FROM {$this->table}";
        return $this;
    }

    public function where(string|array $condition): Entity
    {
        if (is_string($condition)) {
            $this->where = $condition;
            return $this;
        }

        $attribute = $condition[0];
        $operator = count($condition) === 2 ? '=' : $condition[1];
        $value = count($condition) === 2 ? $condition[1] : $condition[2];

        $this->where = "{$attribute} {$operator} {$value}";
        return $this;
    }

    public function order(string $attribute, string $ordering): Entity
    {
        $this->order = "{$attribute} {$ordering}";
        return $this;
    }

    public function limit(int $limit): Entity
    {
        $this->limit = $limit;
        return $this;
    }

    public function fetch(bool $all = true)
    {
        $select = $this->select;

        if(trim($select) === '') {
            return [];
        }

        $where = !$this->where ? '' : "WHERE {$this->where}";
        $order = !$this->order ? '' : "ORDER BY {$this->order}";
        $limit = !$this->limit ? '' : "LIMIT {$this->limit}";

        $query = trim("{$select} {$where} {$order} {$limit}") . ';';

        $prepare = Connection::get()->prepare($query);
        $prepare->execute();

        $this->clearSelect();

        if($all) {
            return $prepare->fetchAll(PDO::FETCH_CLASS, static::class);
        }

        return $prepare->fetchObject(static::class);
    }

    public function first()
    {
        return $this->fetch(false);
    }

    public function count(): int
    {
        $select = "SELECT COUNT(*) AS total FROM {$this->table}";
        $where = !$this->where ? '' : "WHERE {$this->where}";
        $query = trim("{$select} {$where}") . ';';

        $this->clearSelect();

        return Connection::get()->query($query)->fetch(PDO::FETCH_OBJ)->total;
    }

    private function clearSelect() {
        $this->select = '';
        $this->where  = '';
        $this->order  = '';
        $this->limit  = 0;
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    protected function hasOne(string $entityClass, string $foreignKey): Entity
    {
        $entity = new $entityClass();
        return $entity->select()->where([$foreignKey, $this->{$this->primaryKey}])->first();
    }

    protected function belongsTo(string $entityClass, string $foreignKey, string $primaryKey): Entity
    {
        $entity = new $entityClass();
        return $entity->select()->where([$primaryKey, $this->{$foreignKey}])->first();
    }

    protected function hasMany(string $entityClass, string $foreignKey,): array
    {
        $entity = new $entityClass();
        return $entity->select()->where([$foreignKey, $this->{$this->primaryKey}])->fetch();
    }

    protected function belongsToAssociation(
        string $entity,
        string $entityPrimaryKey,
        string $entityAssociation,
        string $primaryKeyInAssociation,
        string $primaryKeyEntityInAssociation
    ): array
    {
        $associations = (new $entityAssociation())
            ->select()
            ->where([$primaryKeyInAssociation, $this->{$this->primaryKey}])
            ->fetch();

        $entity = new $entity();
        $entitys = [];

        foreach ($associations as $association) {
            $entitys = array_merge(
                $entitys,
                ($entity
                    ->select()
                    ->where([
                        $entityPrimaryKey,
                        $association->{$primaryKeyEntityInAssociation}
                        ]
                    )
                    ->fetch()
                )
            );
        }

        return $entitys;
    }

    protected function beforeInsert(): void {}
    protected function afterInsert(): void {}
    protected function beforeUpdate(): void {}
    protected function afterUpdate(): void {}
    protected function beforeDelete(): void {}
    protected function afterDelete(): void {}

    private static function getTypePDOParam(string|int|bool|null $parameter): int
    {
        switch (true) {
            case is_bool($parameter):
                $type = PDO::PARAM_BOOL;
            break;
            case is_int($parameter):
                $type = PDO::PARAM_INT;
            break;
            case is_null($parameter):
                $type = PDO::PARAM_NULL;
                break;
            default:
                $type = PDO::PARAM_STR;
        }

        return $type;
    }

    private function getQueryInsert(): string
    {
        $table = $this->table;
        $columns = $this->getColumnsInsert();
        $values = $this->getValuesForBind();

        return "INSERT INTO {$table} ({$columns}) VALUES ({$values});";
    }

    private function getColumnsInsert(): string
    {
        return implode(', ', $this->attributes);
    }

    private function getValuesForBind(): string
    {
        $valuesForBind = [];
        foreach($this->attributes as $attribute) {
            $valuesForBind[] = ":{$attribute}";
        }
        return implode(', ', $valuesForBind);
    }

    public function getQueryUpdate(): string
    {
        $table = $this->table;
        $columnsValues = $this->getColumnsValuesUpdate();
        $primaryKey = $this->primaryKey;

        return "UPDATE {$table} SET {$columnsValues} WHERE {$primaryKey} = :id;";
    }

    private function getColumnsValuesUpdate(): string
    {
        $attributeValue = [];
        foreach ($this->attributes as $attribute) {
            $attributeValue[] = "{$attribute} = :{$attribute}";
        }

        return implode(', ', $attributeValue);
    }

    public function bindValues($prepare, array $attributes): void
    {
        foreach ($attributes as $attribute) {

            $parameter = isset($attribute['description']) ? ":{$attribute['description']}" : ":{$attribute}";
            $value = isset($attribute['value']) ? $attribute['value'] : $this->{$attribute};
            $type = self::getTypePDOParam($value);

            $prepare->bindValue($parameter, $value, $type);
        }
    }
}