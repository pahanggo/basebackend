<?php

namespace Backpack\CRUD\app\Models\Traits;

trait CrudTrait
{
    use HasEnumFields;
    use HasFakeFields;
    use HasIdentifiableAttribute;
    use HasRelationshipFields;
    use HasTranslatableFields;
    use HasUploadFields;

    public static function hasCrudTrait()
    {
        return true;
    }
}
