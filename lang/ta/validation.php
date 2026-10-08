<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => ':attribute ஏற்றுக்கொள்ளப்பட வேண்டும்.',
    'active_url' => ':attribute சரியான URL அல்ல.',
    'after' => ':attribute :date க்குப் பிறகான தேதியாக இருக்க வேண்டும்.',
    'after_or_equal' => ':attribute :date அல்லது அதற்குப் பிறகான தேதியாக இருக்க வேண்டும்.',
    'alpha' => ':attribute எழுத்துகளை மட்டுமே கொண்டிருக்க வேண்டும்.',
    'alpha_dash' => ':attribute எழுத்துகள், எண்கள், இடைக்கோடுகள் மற்றும் அடிக்கோடுகளை மட்டுமே கொண்டிருக்க வேண்டும்.',
    'alpha_num' => ':attribute எழுத்துகள் மற்றும் எண்களை மட்டுமே கொண்டிருக்க வேண்டும்.',
    'array' => ':attribute ஒரு அணியாக இருக்க வேண்டும்.',
    'before' => ':attribute :date க்கு முந்தைய தேதியாக இருக்க வேண்டும்.',
    'before_or_equal' => ':attribute :date அல்லது அதற்கு முந்தைய தேதியாக இருக்க வேண்டும்.',
    'between' => [
        'numeric' => ':attribute :min மற்றும் :max க்கு இடையில் இருக்க வேண்டும்.',
        'file' => ':attribute :min மற்றும் :max கிலோபைட்டுகளுக்கு இடையில் இருக்க வேண்டும்.',
        'string' => ':attribute :min மற்றும் :max எழுத்துகளுக்கு இடையில் இருக்க வேண்டும்.',
        'array' => ':attribute :min முதல் :max உருப்படிகளைக் கொண்டிருக்க வேண்டும்.',
    ],
    'boolean' => ':attribute புலம் true அல்லது false ஆக இருக்க வேண்டும்.',
    'confirmed' => ':attribute உறுதிப்படுத்தல் பொருந்தவில்லை.',
    'date' => ':attribute சரியான தேதி அல்ல.',
    'date_equals' => ':attribute :date க்கு சமமான தேதியாக இருக்க வேண்டும்.',
    'date_format' => ':attribute :format வடிவத்துடன் பொருந்தவில்லை.',
    'different' => ':attribute மற்றும் :other வேறுபட்டிருக்க வேண்டும்.',
    'digits' => ':attribute :digits இலக்கங்களாக இருக்க வேண்டும்.',
    'digits_between' => ':attribute :min முதல் :max இலக்கங்களுக்கு இடையில் இருக்க வேண்டும்.',
    'dimensions' => ':attribute படத்தின் பரிமாணங்கள் தவறானவை.',
    'distinct' => ':attribute புலத்தில் மறுநகல் மதிப்பு உள்ளது.',
    'email' => ':attribute சரியான மின்னஞ்சல் முகவரியாக இருக்க வேண்டும்.',
    'ends_with' => ':attribute பின்வருவனவற்றில் ஒன்றில் முடிய வேண்டும்: :values.',
    'exists' => 'தேர்ந்தெடுக்கப்பட்ட :attribute தவறானது.',
    'file' => ':attribute ஒரு கோப்பாக இருக்க வேண்டும்.',
    'filled' => ':attribute புலத்தில் மதிப்பு இருக்க வேண்டும்.',
    'gt' => [
        'numeric' => ':attribute :value ஐ விட அதிகமாக இருக்க வேண்டும்.',
        'file' => ':attribute :value கிலோபைட்டுகளை விட அதிகமாக இருக்க வேண்டும்.',
        'string' => ':attribute :value எழுத்துகளை விட அதிகமாக இருக்க வேண்டும்.',
        'array' => ':attribute :value உருப்படிகளை விட அதிகமாக இருக்க வேண்டும்.',
    ],
    'gte' => [
        'numeric' => ':attribute :value ஐ விட அதிகமாகவோ அதற்கு சமமாகவோ இருக்க வேண்டும்.',
        'file' => ':attribute :value கிலோபைட்டுகளை விட அதிகமாகவோ அதற்கு சமமாகவோ இருக்க வேண்டும்.',
        'string' => ':attribute :value எழுத்துகளை விட அதிகமாகவோ அதற்கு சமமாகவோ இருக்க வேண்டும்.',
        'array' => ':attribute :value அல்லது அதற்கு மேற்பட்ட உருப்படிகளைக் கொண்டிருக்க வேண்டும்.',
    ],
    'image' => ':attribute ஒரு படமாக இருக்க வேண்டும்.',
    'in' => 'தேர்ந்தெடுக்கப்பட்ட :attribute தவறானது.',
    'in_array' => ':attribute புலம் :other இல் இல்லை.',
    'integer' => ':attribute ஒரு முழு எண்ணாக இருக்க வேண்டும்.',
    'ip' => ':attribute சரியான IP முகவரியாக இருக்க வேண்டும்.',
    'ipv4' => ':attribute சரியான IPv4 முகவரியாக இருக்க வேண்டும்.',
    'ipv6' => ':attribute சரியான IPv6 முகவரியாக இருக்க வேண்டும்.',
    'json' => ':attribute சரியான JSON சரமாக இருக்க வேண்டும்.',
    'lt' => [
        'numeric' => ':attribute :value ஐ விட குறைவாக இருக்க வேண்டும்.',
        'file' => ':attribute :value கிலோபைட்டுகளை விட குறைவாக இருக்க வேண்டும்.',
        'string' => ':attribute :value எழுத்துகளை விட குறைவாக இருக்க வேண்டும்.',
        'array' => ':attribute :value உருப்படிகளை விட குறைவாக இருக்க வேண்டும்.',
    ],
    'lte' => [
        'numeric' => ':attribute :value ஐ விட குறைவாகவோ அதற்கு சமமாகவோ இருக்க வேண்டும்.',
        'file' => ':attribute :value கிலோபைட்டுகளை விட குறைவாகவோ அதற்கு சமமாகவோ இருக்க வேண்டும்.',
        'string' => ':attribute :value எழுத்துகளை விட குறைவாகவோ அதற்கு சமமாகவோ இருக்க வேண்டும்.',
        'array' => ':attribute :value உருப்படிகளுக்கு மேல் இருக்கக்கூடாது.',
    ],
    'max' => [
        'numeric' => ':attribute :max ஐ விட அதிகமாக இருக்கக்கூடாது.',
        'file' => ':attribute :max கிலோபைட்டுகளை விட அதிகமாக இருக்கக்கூடாது.',
        'string' => ':attribute :max எழுத்துகளை விட அதிகமாக இருக்கக்கூடாது.',
        'array' => ':attribute :max உருப்படிகளுக்கு மேல் இருக்கக்கூடாது.',
    ],
    'mimes' => ':attribute :values வகை கோப்பாக இருக்க வேண்டும்.',
    'mimetypes' => ':attribute :values வகை கோப்பாக இருக்க வேண்டும்.',
    'min' => [
        'numeric' => ':attribute குறைந்தது :min ஆக இருக்க வேண்டும்.',
        'file' => ':attribute குறைந்தது :min கிலோபைட்டுகளாக இருக்க வேண்டும்.',
        'string' => ':attribute குறைந்தது :min எழுத்துகளாக இருக்க வேண்டும்.',
        'array' => ':attribute குறைந்தது :min உருப்படிகளைக் கொண்டிருக்க வேண்டும்.',
    ],
    'multiple_of' => ':attribute :value இன் பெருக்கமாக இருக்க வேண்டும்.',
    'not_in' => 'தேர்ந்தெடுக்கப்பட்ட :attribute தவறானது.',
    'not_regex' => ':attribute வடிவம் தவறானது.',
    'numeric' => ':attribute ஒரு எண்ணாக இருக்க வேண்டும்.',
    'password' => 'கடவுச்சொல் தவறானது.',
    'present' => ':attribute புலம் இருக்க வேண்டும்.',
    'regex' => ':attribute வடிவம் தவறானது.',
    'required' => ':attribute புலம் அவசியம்.',
    'required_if' => ':other :value ஆக இருக்கும்போது :attribute புலம் அவசியம்.',
    'required_unless' => ':other :values இல் இல்லாவிட்டால் :attribute புலம் அவசியம்.',
    'required_with' => ':values இருக்கும்போது :attribute புலம் அவசியம்.',
    'required_with_all' => ':values இருக்கும்போது :attribute புலம் அவசியம்.',
    'required_without' => ':values இல்லாதபோது :attribute புலம் அவசியம்.',
    'required_without_all' => ':values எதுவும் இல்லாதபோது :attribute புலம் அவசியம்.',
    'prohibited_if' => ':other :value ஆக இருக்கும்போது :attribute புலம் அனுமதிக்கப்படவில்லை.',
    'prohibited_unless' => ':other :values இல் இல்லாவிட்டால் :attribute புலம் அனுமதிக்கப்படவில்லை.',
    'same' => ':attribute மற்றும் :other பொருந்த வேண்டும்.',
    'size' => [
        'numeric' => ':attribute :size ஆக இருக்க வேண்டும்.',
        'file' => ':attribute :size கிலோபைட்டுகளாக இருக்க வேண்டும்.',
        'string' => ':attribute :size எழுத்துகளாக இருக்க வேண்டும்.',
        'array' => ':attribute :size உருப்படிகளைக் கொண்டிருக்க வேண்டும்.',
    ],
    'starts_with' => ':attribute பின்வருவனவற்றில் ஒன்றில் தொடங்க வேண்டும்: :values.',
    'string' => ':attribute ஒரு சரமாக இருக்க வேண்டும்.',
    'timezone' => ':attribute சரியான நேர மண்டலமாக இருக்க வேண்டும்.',
    'unique' => ':attribute ஏற்கெனவே பயன்பாட்டில் உள்ளது.',
    'uploaded' => ':attribute பதிவேற்ற முடியவில்லை.',
    'url' => ':attribute வடிவம் தவறானது.',
    'uuid' => ':attribute சரியான UUID ஆக இருக்க வேண்டும்.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [],

];
