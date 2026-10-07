<?php

declare(strict_types=1);

/**
 * Contains all of the translation strings for different activity log
 * events. These should be keyed by the value in front of the colon (:)
 * in the event name. If there is no colon present, they should live at
 * the top level.
 */
return [
    'auth' => [
        'fail' => 'लगइन असफल भयो',
        'success' => 'लगइन भयो',
        'password-reset' => 'पासवर्ड रिसेट भयो',
        'reset-password' => 'पासवर्ड रिसेटको अनुरोध गरियो',
        'checkpoint' => '२-चरण प्रमाणीकरण अनुरोध गरियो',
        'recovery-token' => '२-चरण रिकभरी टोकन प्रयोग गरियो',
        'token' => '२-चरण चुनौती समाधान गरियो',
        'ip-blocked' => ':identifier का लागि असूचीकृत आईपी ठेगानाबाट अनुरोध रोकियो',
        'sftp' => [
            'fail' => 'SFTP लगइन असफल भयो',
        ],
    ],
    'user' => [
        'user' => [
            'create' => 'नयाँ प्रयोगकर्ता :email सिर्जना गरियो',
        ],
        'account' => [
            'email-changed' => 'इमेल :old बाट :new मा परिवर्तन गरियो',
            'password-changed' => 'पासवर्ड परिवर्तन गरियो',
            'language-changed' => 'भाषा :old बाट :new मा परिवर्तन गरियो',
        ],
        'api-key' => [
            'create' => 'नयाँ API कुञ्जी :identifier सिर्जना गरियो',
            'delete' => 'API कुञ्जी :identifier मेटाइयो',
        ],
        'ssh-key' => [
            'create' => 'खातामा SSH कुञ्जी :fingerprint थपियो',
            'delete' => 'खाताबाट SSH कुञ्जी :fingerprint हटाइयो',
        ],
        'two-factor' => [
            'create' => '२-चरण प्रमाणीकरण सक्षम गरियो',
            'delete' => '२-चरण प्रमाणीकरण अक्षम गरियो',
        ],
    ],
    'server' => [
        'reinstall' => 'सर्भर पुनः स्थापना गरियो',
        'console' => [
            'command' => 'सर्भरमा ":command" आदेश चलाइयो',
        ],
        'power' => [
            'start' => 'सर्भर सुरु गरियो',
            'stop' => 'सर्भर बन्द गरियो',
            'restart' => 'सर्भर पुनः सुरु गरियो',
            'kill' => 'सर्भर प्रक्रिया जबरजस्ती बन्द (Kill) गरियो',
        ],
        'backup' => [
            'download' => ':name ब्याकअप डाउनलोड गरियो',
            'delete' => ':name ब्याकअप मेटाइयो',
            'restore' => ':name ब्याकअप पुनर्स्थापना गरियो (मेटाइएका फाइलहरू: :truncate)',
            'restore-complete' => ':name ब्याकअपको पुनर्स्थापना सम्पन्न भयो',
            'restore-failed' => ':name ब्याकअपको पुनर्स्थापना सम्पन्न गर्न असफल भयो',
            'start' => 'नयाँ ब्याकअप :name सुरु गरियो',
            'complete' => ':name ब्याकअप सम्पन्न भएको रूपमा चिन्हित गरियो',
            'fail' => ':name ब्याकअप असफल भएको रूपमा चिन्हित गरियो',
            'lock' => ':name ब्याकअप लक गरियो',
            'unlock' => ':name ब्याकअप अनलक गरियो',
        ],
        'database' => [
            'create' => 'नयाँ डाटाबेस :name सिर्जना गरियो',
            'rotate-password' => 'डाटाबेस :name का लागि पासवर्ड परिवर्तन गरियो',
            'delete' => 'डाटाबेस :name मेटाइयो',
        ],
        'file' => [
            'compress_one' => ':directory:files.0 कम्प्रेस गरियो',
            'compress_other' => ':directory मा :count फाइलहरू कम्प्रेस गरियो',
            'read' => ':file को सामग्री हेरियो',
            'copy' => ':file को प्रतिलिपि बनाइयो',
            'create-directory' => 'डाइरेक्टरी :directory:name सिर्जना गरियो',
            'decompress' => ':directory मा :files अनजिप (Decompress) गरियो',
            'delete_one' => ':directory:files.0 मेटाइयो',
            'delete_other' => ':directory मा :count फाइलहरू मेटाइयो',
            'download' => ':file डाउनलोड गरियो',
            'pull' => ':url बाट :directory मा रिमोट फाइल डाउनलोड गरियो',
            'rename_one' => ':directory:files.0.from लाई :directory:files.0.to मा नाम परिवर्तन गरियो',
            'rename_other' => ':directory मा :count फाइलहरूको नाम परिवर्तन गरियो',
            'write' => ':file मा नयाँ सामग्री लेखियो',
            'upload' => 'फाइल अपलोड सुरु गरियो',
            'uploaded' => ':directory:file अपलोड गरियो',
        ],
        'sftp' => [
            'denied' => 'अनुमतिका कारण SFTP पहुँच अस्वीकार गरियो',
            'create_one' => ':files.0 सिर्जना गरियो',
            'create_other' => ':count नयाँ फाइलहरू सिर्जना गरियो',
            'write_one' => ':files.0 को सामग्री परिमार्जन गरियो',
            'write_other' => ':count फाइलहरूको सामग्री परिमार्जन गरियो',
            'delete_one' => ':files.0 मेटाइयो',
            'delete_other' => ':count फाइलहरू मेटाइयो',
            'create-directory_one' => ':files.0 डाइरेक्टरी सिर्जना गरियो',
            'create-directory_other' => ':count डाइरेक्टरीहरू सिर्जना गरियो',
            'rename_one' => ':files.0.from लाई :files.0.to मा नाम परिवर्तन गरियो',
            'rename_other' => ':count फाइलहरूको नाम परिवर्तन वा सारियो',
        ],
        'allocation' => [
            'create' => 'सर्भरमा :allocation थपियो',
            'notes' => ':allocation का लागि कैफियत ":old" बाट ":new" मा अद्यावधिक गरियो',
            'primary' => ':allocation लाई प्राथमिक सर्भर बाँडफाँडको रूपमा तोकियो',
            'delete' => ':allocation बाँडफाँड मेटाइयो',
        ],
        'schedule' => [
            'create' => ':name कार्यतालिका सिर्जना गरियो',
            'update' => ':name कार्यतालिका अद्यावधिक गरियो',
            'execute' => ':name कार्यतालिका म्यानुअल रूपमा चलाइयो',
            'delete' => ':name कार्यतालिका मेटाइयो',
        ],
        'task' => [
            'create' => ':name कार्यतालिकाका लागि नयाँ ":action" कार्य सिर्जना गरियो',
            'update' => ':name कार्यतालिकाका लागि ":action" कार्य अद्यावधिक गरियो',
            'delete' => ':name कार्यतालिकाका लागि एउटा कार्य मेटाइयो',
        ],
        'settings' => [
            'rename' => 'सर्भरको नाम :old बाट :new मा परिवर्तन गरियो',
            'description' => 'सर्भर विवरण :old बाट :new मा परिवर्तन गरियो',
        ],
        'startup' => [
            'edit' => ':variable चर ":old" बाट ":new" मा परिवर्तन गरियो',
            'image' => 'सर्भरका लागि डकर छवि :old बाट :new मा अद्यावधिक गरियो',
        ],
        'subuser' => [
            'create' => ':email लाई उप-प्रयोगकर्ताको रूपमा थपियो',
            'update' => ':email का लागि उप-प्रयोगकर्ता अनुमतिहरू अद्यावधिक गरियो',
            'delete' => ':email लाई उप-प्रयोगकर्ताबाट हटाइयो',
        ],
    ],
    'admin' => [
        'api-key' => [
            'create' => 'एप्लिकेसन API कुञ्जी :identifier सिर्जना गरियो',
            'update' => 'एप्लिकेसन API कुञ्जी :identifier अद्यावधिक गरियो',
            'delete' => 'एप्लिकेसन API कुञ्जी :identifier मेटाइयो',
        ],
        'database-host' => [
            'create' => ':name डाटाबेस होस्ट सिर्जना गरियो',
            'update' => ':name डाटाबेस होस्ट अद्यावधिक गरियो',
            'delete' => ':name डाटाबेस होस्ट मेटाइयो',
        ],
        'egg' => [
            'create' => ':name एग सिर्जना गरियो',
            'update' => ':name एग अद्यावधिक गरियो',
            'delete' => ':name एग मेटाइयो',
            'import' => ':name एग आयात गरियो',
            'update-import' => 'आयातबाट :name एग अद्यावधिक गरियो',
            'scripts' => ':name एगका लागि स्थापना लिपि अद्यावधिक गरियो',
            'tags' => 'एगका लागि ट्यागहरू अद्यावधिक गरियो',
            'tags_one' => 'एग ट्यागहरू :tags.0 मा तोकियो',
            'tags_other' => 'एगमा :count ट्यागहरू तोकियो',
        ],
        'egg-variable' => [
            'create' => ':name एग चर सिर्जना गरियो',
            'update' => ':name एग चर अद्यावधिक गरियो',
            'delete' => ':name एग चर मेटाइयो',
            'reorder' => 'एगका लागि चरहरूको क्रम परिवर्तन गरियो',
        ],
        'location' => [
            'create' => ':short स्थान सिर्जना गरियो',
            'update' => ':short स्थान अद्यावधिक गरियो',
            'delete' => ':short स्थान मेटाइयो',
        ],
        'mount' => [
            'create' => ':name माउन्ट सिर्जना गरियो',
            'update' => ':name माउन्ट अद्यावधिक गरियो',
            'delete' => ':name माउन्ट मेटाइयो',
            'attach-egg' => ':eggs एगहरूमा माउन्ट संलग्न गरियो',
            'attach-egg_one' => 'माउन्टमा एउटा एग संलग्न गरियो',
            'attach-egg_other' => 'माउन्टमा :count एगहरू संलग्न गरियो',
            'detach-egg' => ':egg एगबाट माउन्ट छुट्याइयो',
            'attach-node' => ':nodes नोडहरूमा माउन्ट संलग्न गरियो',
            'attach-node_one' => 'माउन्टमा एउटा नोड संलग्न गरियो',
            'attach-node_other' => 'माउन्टमा :count नोडहरू संलग्न गरियो',
            'detach-node' => ':node नोडबाट माउन्ट छुट्याइयो',
        ],
        'node' => [
            'create' => ':name नोड सिर्जना गरियो',
            'update' => ':name नोड अद्यावधिक गरियो',
            'delete' => ':name नोड मेटाइयो',
            'deploy-token' => ':name नोडका लागि डिप्लोयमेन्ट टोकन उत्पन्न गरियो',
            'tags' => 'नोडका लागि एग ट्यागहरू अद्यावधिक गरियो',
            'tags_one' => 'नोड ट्यागहरू :tags.0 मा तोकियो',
            'tags_other' => 'नोडमा :count ट्यागहरू तोकियो',
            'deployment-tags' => 'नोडका लागि डिप्लोयमेन्ट ट्यागहरू अद्यावधिक गरियो',
            'deployment-tags_one' => 'नोड डिप्लोयमेन्ट ट्यागहरू :tags.0 मा तोकियो',
            'deployment-tags_other' => 'नोडमा :count डिप्लोयमेन्ट ट्यागहरू तोकियो',
        ],
        'node-allocation' => [
            'create' => 'नोडमा :address_count ठेगानाहरूमा :ports_count पोर्टहरू थपियो',
            'alias' => 'बाँडफाँड उपनाम :alias मा तोकियो',
            'delete' => ':address मा पोर्ट :port मेटाइयो',
            'delete-block' => ':address मा नतोकिएका बाँडफाँडहरू मेटाइयो',
        ],
        'server' => [
            'create' => ':name सर्भर सिर्जना गरियो',
            'delete' => ':name सर्भर मेटाइयो',
            'details' => ':name का लागि विवरणहरू अद्यावधिक गरियो',
            'build' => ':name का लागि निर्माण (Build) कन्फिगरेसन अद्यावधिक गरियो',
            'startup' => ':name का लागि स्टार्टअप कन्फिगरेसन अद्यावधिक गरियो',
            'reinstall' => ':name पुनः स्थापना गरियो',
            'rebuild' => ':name को पुनः निर्माण सुरु गरियो',
            'suspend' => ':name निलम्बित गरियो',
            'unsuspend' => ':name फुकुवा गरियो',
            'toggle-install' => ':name को स्थापना स्थिति टगल गरियो',
            'transfer' => ':name लाई नोड :node_id मा स्थानान्तरण सुरु गरियो',
            'mount' => 'सर्भरमा :name माउन्ट थपियो',
            'unmount' => 'सर्भरबाट :name माउन्ट हटाइयो',
        ],
        'server-backup' => [
            'toggle-lock' => ':name ब्याकअपको लक टगल गरियो',
        ],
        'server-database' => [
            'create' => ':name डाटाबेस सिर्जना गरियो',
            'delete' => ':name डाटाबेस मेटाइयो',
            'rotate-password' => ':name डाटाबेसका लागि पासवर्ड परिवर्तन गरियो',
        ],
        'tag' => [
            'create' => ':slug ट्याग सिर्जना गरियो',
            'update' => ':slug ट्याग अद्यावधिक गरियो',
            'delete' => ':slug ट्याग मेटाइयो',
        ],
        'user' => [
            'create' => 'प्रयोगकर्ता :email सिर्जना गरियो',
            'update' => 'प्रयोगकर्ता :email अद्यावधिक गरियो',
            'delete' => 'प्रयोगकर्ता :email मेटाइयो',
            'disable-2fa' => ':email का लागि २-चरण प्रमाणीकरण अक्षम गरियो',
        ],
    ],
];

