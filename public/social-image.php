<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/social_image_endpoint.php';

pcf_social_image_run([
    'service_key' => 'pinkclub-fl',
    'allowed_hosts' => ['dmm.co.jp', 'dmm.com', 'fanza.co.jp'],
    'candidate_fields' => ['image_large', 'full_package_url', 'main_image_url', 'image_url', 'image_small', 'image_list'],
    'raw_candidate_fields' => ['imageURL', 'packageImage', 'image', 'images', 'sampleImageURL'],
    'referer' => 'https://www.dmm.co.jp/',
    'user_agent' => 'Mozilla/5.0 (compatible; PinkClub-FL-SocialCard/2.0; +https://pinkclub-fl.com/)',
]);
