

---

# Laravel Generate Request

Artisan-команда для автоматической генерации FormRequest классов в Laravel. Пакет позволяет быстро создавать FormRequest классы на основе модели, используя правила валидации из базы данных или кастомные правила, указанные в модели.

---

## Установка

Установите пакет через Composer:  
``` bash 
composer require desole/laravel-generate-request

```

---

## Использование

### Основная команда
php artisan request:make {Model} {Name} {Type} {--only-custom}

#### Параметры:
- `Model` (обязательный): Имя модели, на основе которой будет создан FormRequest класс. Модель должна иметь заполняемые поля (`$fillable`). Модель ищется в папке App/Models 
- `Name` (обязательный): Имя создаваемого FormRequest класса. Например, UserStoreRequest.
- `Type` (опциональный): Тип FormRequest класса. Возможные значения:
    - store — поля из метода getStoreFields модели.
    - update — поля из метода getUpdateFields модели.
    - delete — поля из метода getDeleteFields модели.

  Если в имени (`Name`) содержится одно из этих слов (например, `UserStoreRequest`), тип будет определен автоматически.

  Если метод для указанного типа отсутствует в модели, будут использованы поля из $fillable.

- `--only-custom` (опциональный): Если указан, в FormRequest класс попадут только те поля, которые явно указаны в методе модели для выбранного типа.

---

### Примеры использования

#### 1. Генерация FormRequest для создания сущности (Store)
php artisan request:make User UserStoreRequest store

- Если в модели User есть метод getStoreFields, будут использованы его правила.
- Если метод отсутствует, будут использованы поля из $fillable.

#### 2. Генерация FormRequest для обновления сущности (Update)
php artisan request:make User UserUpdateRequest update

- Если в модели User есть метод getUpdateFields, будут использованы его правила.
- Если метод отсутствует, будут использованы поля из $fillable.

#### 3. Генерация FormRequest с автоматическим определением типа
php artisan request:make User UserStoreRequest

- Тип store будет определен автоматически из имени UserStoreRequest.

#### 4. Генерация FormRequest только с кастомными правилами
php artisan request:make User UserStoreRequest store --only-custom

- В FormRequest попадут только те поля, которые указаны в методе getStoreFields модели.

---

### Кастомные правила в модели

Вы можете указать правила валидации непосредственно в модели. Для этого добавьте методы getStoreFields, getUpdateFields или getDeleteFields.

#### Пример модели:

    {
    namespace App\Models;
    
    use Illuminate\Database\Eloquent\Model;
    
    class User extends Model { protected $fillable = ['name', 'email', 'password'];

    public function getStoreFields()
    {
        return [
            'email' => 'required|email|unique:users,email', // Кастомные правила
            'name', // Поле без правил (будут использованы правила из базы данных)
            'custom_field' => 'string|max:100', // Поле, которого нет в базе данных
        ];
    }

    public function getUpdateFields()
    {
        return [
            'email' => 'required|email|unique:users,email,' . $this->id,
            'name' => 'nullable|string|max:255',
        ];
    }
}

#### Правила:
- Если поле указано без правил (например, `'name'`), будут использованы правила из базы данных.
- Если поле указано с правилами (например, `'email' => 'required|email'`), они будут использованы как есть.
- Если поле отсутствует в базе данных, оно будет добавлено с указанными правилами.

---

### Пример сгенерированного FormRequest

#### Для команды:php artisan request:make User UserStoreRequest store

#### Результат:namespace App\Http\Requests;

    use Illuminate\Foundation\Http\FormRequest;
    
    class UserStoreRequest extends FormRequest
        {
            public function rules()
            {
            return [
                    'email' => ['required', 'email', 'unique:users,email'],
                    'name' => ['required', 'string', 'max:255'],
                    'password' => ['required', 'string', 'min:8'],
                    'custom_field' => ['string', 'max:100'],
                ];
            }
        }

---

### Логика работы

1. Поля из базы данных:
    - Если поле есть в базе данных, но не указано в кастомных правилах, используются правила на основе типа данных, внешних ключей и уникальных индексов.

2. Кастомные поля:
    - Если поле указано в кастомных правилах, но отсутствует в базе данных, оно добавляется с указанными правилами.
    - Если поле указано в кастомных правилах без правил (пустая строка), но присутствует в базе данных, используются правила из базы данных.

3. Режим `--only-custom`:
    - В FormRequest попадают только те поля, которые явно указаны в кастомных правилах.

---

### Поддерживаемые типы данных

Пакет автоматически определяет правила валидации на основе типов данных в базе данных. Например:
- varchar(255) → string|max:255
- int → integer
- date → date
- json → array
- и т.д.

---
### Поддерживаемые базы данных

- Mysql
- Postgresql

---

### Лицензия

Этот пакет распространяется под лицензией MIT. См. файл [LICENSE](LICENSE) для получения дополнительной информации.

---

### Автор

- Автор: MyDesole

---

### Благодарности

Спасибо за использование этого пакета! Если у вас есть предложения или вопросы, создайте issue на GitHub.
