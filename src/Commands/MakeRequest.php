<?php

namespace desole\MakeRequest\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class MakeRequest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'request:make {model} {name} {type=none} {--only-custom}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    const REQUEST_TYPES = [
      'store', 'update','delete',
    ];

    const TYPE_FOR_NON_FOREIGNS = [
        'varchar(255)' => ['string', 'max:255'],
        'tinyint(1)' => ['boolean'],
        'int' => ['integer'],
        'bigint' => ['integer'],
        'text' => ['string'],
        'longtext' => ['string'],
        'date' => ['date'],
        'datetime' => ['date'],
        'timestamp' => ['date'],
        'time' => ['date_format:H:i:s'],
        'year' => ['date_format:Y'],
        'float' => ['numeric'],
        'double' => ['numeric'],
        'decimal' => ['numeric'],
        'json' => ['array'],
        'enum' => ['string'],
        'uuid' => ['uuid'],
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $model = $this->argument('model');
        $type = $this->argument('type');
        $name = $this->argument('name');
        $onlyCustom = $this->option('only-custom');

        if ($type === 'none') {
            foreach (self::REQUEST_TYPES as $request_type) {
                if (str_contains(strtolower($name), $request_type)) {
                    $type = $request_type;
                }
            }
        }

        $modelClass = "App\\Models\\" . $model;
        if (!class_exists($modelClass)) {
            $this->error("Model $modelClass does not exist.");
            return self::FAILURE;
        }
        $model = new $modelClass;

        $customRulesMethod = 'get' . ucfirst($type) . 'Fields';
        $customRules = method_exists($model, $customRulesMethod) ? $model->{$customRulesMethod}() : [];

        $tableName = $model->getTable();
        $schemaName = config('database.connections.' . config('database.default') . '.database');

        $fields = $this->getFieldsFromDatabase($tableName);
        $foreignKeys = $this->getForeignKeys($tableName, $schemaName);
        $uniqueColumns = $this->getUniqueColumns($tableName, $schemaName);

        $requestFields = $this->generateRules($fields, $foreignKeys, $uniqueColumns, $model, $type, $customRules, $onlyCustom);

        $this->createRequestFile($name, $requestFields);

        $this->info('Request created successfully.');
        return self::SUCCESS;
    }

    /**
     * Получение полей из базы данных.
     */
    private function getFieldsFromDatabase($tableName)
    {
        if (config('database.default') === 'mysql') {
            return DB::select('SHOW COLUMNS FROM ' . $tableName);
        } elseif (config('database.default') === 'pgsql') {
            return DB::select("
                SELECT
                    column_name as Field,
                    data_type as Type,
                    is_nullable as Null,
                    column_default as Default,
                    '' as Extra
                FROM
                    information_schema.columns
                WHERE
                    table_name = ?
                    AND table_schema = ?
            ", [$tableName, 'public']);
        } else {
            throw new \Exception('Unsupported database type');
        }
    }

    /**
     * Получение внешних ключей.
     */
    private function getForeignKeys($tableName, $schemaName)
    {
        if (config('database.default') === 'mysql') {
            return DB::select("
                SELECT
                    COLUMN_NAME as column_name,
                    REFERENCED_TABLE_NAME as referenced_table_name,
                    REFERENCED_COLUMN_NAME as referenced_column_name
                FROM
                    information_schema.KEY_COLUMN_USAGE
                WHERE
                    TABLE_NAME = ?
                    AND TABLE_SCHEMA = ?
                    AND REFERENCED_TABLE_NAME IS NOT NULL
            ", [$tableName, $schemaName]);
        } elseif (config('database.default') === 'pgsql') {
            return DB::select("
                SELECT
                    kcu.column_name AS column_name,
                    ccu.table_name AS referenced_table_name,
                    ccu.column_name AS referenced_column_name
                FROM
                    information_schema.table_constraints AS tc
                    JOIN information_schema.key_column_usage AS kcu
                      ON tc.constraint_name = kcu.constraint_name
                      AND tc.table_schema = kcu.table_schema
                    JOIN information_schema.constraint_column_usage AS ccu
                      ON ccu.constraint_name = tc.constraint_name
                      AND ccu.table_schema = tc.table_schema
                WHERE
                    tc.constraint_type = 'FOREIGN KEY'
                    AND tc.table_name = ?
                    AND tc.table_schema = ?
            ", [$tableName, 'public']);
        } else {
            throw new \Exception('Unsupported database type');
        }
    }

    /**
     * Получение уникальных индексов.
     */
    private function getUniqueColumns($tableName, $schemaName)
    {
        if (config('database.default') === 'mysql') {
            $uniqueIndexes = DB::select("
                SELECT
                    COLUMN_NAME as column_name
                FROM
                    information_schema.STATISTICS
                WHERE
                    TABLE_NAME = ?
                    AND TABLE_SCHEMA = ?
                    AND NON_UNIQUE = 0
            ", [$tableName, $schemaName]);
        } elseif (config('database.default') === 'pgsql') {
            $uniqueIndexes = DB::select("
                SELECT
                    a.attname AS column_name
                FROM
                    pg_index i
                    JOIN pg_attribute a ON a.attnum = ANY(i.indkey) AND a.attrelid = i.indrelid
                WHERE
                    i.indrelid = ?::regclass
                    AND i.indisunique = true
            ", [$tableName]);
        } else {
            throw new \Exception('Unsupported database type');
        }

        return array_map(function ($index) {
            return strtolower($index->column_name);
        }, $uniqueIndexes);
    }

    /**
     * Генерация правил валидации.
     */
    private function generateRules($fields, $foreignKeys, $uniqueColumns, $model, $type, $customRules, $onlyCustom)
    {
        $requestFields = [];

        // Добавляем кастомные правила из модели
        foreach ($customRules as $field => $rule) {
            $requestFields[$field] = $rule === '' ? [] : $rule;
        }


        // Добавляем правила для полей из базы данных
        foreach ($fields as $field) {
            $fieldName = strtolower($field->Field ?? $field->field);

            if (in_array($fieldName, $requestFields)) {
                $requestFields = array_filter($requestFields, function ($value) use ($fieldName) {
                    return $value !== $fieldName;
                });
            }

            if (isset($requestFields[$fieldName]) && count($requestFields[$fieldName]) > 0) {
                continue;
            }


            if (!in_array($fieldName, $customRules) && $onlyCustom) {
                continue;
            }


            $rules = [];

            // Добавляем правила для внешних ключей
            if (array_key_exists($fieldName, $foreignKeys)) {
                $rules = array_merge($rules, $this->prepairForeign($foreignKeys[$fieldName]));
            }

            // Добавляем правила для уникальных полей
            if (in_array($fieldName, $uniqueColumns)) {
                $rules[] = 'unique:' . $model->getTable() . ',' . $fieldName;
            }

            // Добавляем правило nullable, если поле может быть NULL
            if (($field->Null ?? $field->null) === 'YES') {
                $rules[] = 'nullable';
            }

            // Добавляем правила на основе типа данных
            $rules = array_merge($rules, $this->mapType($field->Type ?? $field->type));

            // Добавляем правила в результирующий массив
            $requestFields[$fieldName] = $rules;
        }


        return $requestFields;
    }

    /**
     * Создание файла запроса.
     */
    private function createRequestFile($name, $requestFields)
    {
        $requestPath = app_path('Http/Requests');
        if (!File::exists($requestPath)) {
            if (!File::makeDirectory($requestPath, 0755, true)) {
                $this->error('Failed to create directory: ' . $requestPath);
                return self::FAILURE;
            }
        }

        $requestFile = $requestPath . '/' . $name . '.php';
        if (File::exists($requestFile)) {
            $this->error('Request already exists!');
            return self::FAILURE;
        }

        $stub = File::get(__DIR__ . '/stubs/request.stub');
        $stub = str_replace('{{request_name}}', $name, $stub);
        $stub = str_replace('{{request_fields}}', $this->formatRules($requestFields), $stub);

        if (!File::put($requestFile, $stub)) {
            $this->error('Failed to write file: ' . $requestFile);
            return self::FAILURE;
        }
    }

    /**
     * Форматирование правил для вставки в шаблон.
     */
    private function formatRules($requestFields)
    {
        $rulesString = "[\n";
        foreach ($requestFields as $field => $rules) {
            if (gettype($rules) == 'string') {
                $rulesString .= "            '$rules' => [],\n";
            }
            else {
                $rulesString .= "            '$field' => [" .   implode(', ', array_map(function ($rule) {
                        return "'$rule'";
                    }, $rules)) . "],\n";
            }
        }
        $rulesString .= "        ]";
        return $rulesString;
    }



	private function mapType($type)
	{
		foreach (self::TYPE_FOR_NON_FOREIGNS as $mysqlType => $validationRules) {
			if (strpos($type, $mysqlType) === 0) {
				return $validationRules;
			}
		}
		return ['string'];
	}

	private function prepairForeign($tableName)
	{
		return ['integer', 'exists:' . $tableName . ',id'];
	}
}
