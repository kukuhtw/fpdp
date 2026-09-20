<?php
declare(strict_types=1);
require_once __DIR__.'/../vendor/autoload.php';
use App\Core\Config;
use App\Services\External\FacebookConnector;
function fb_assert(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
$env=sys_get_temp_dir().'/fpdp-facebook-'.uniqid().'.env';file_put_contents($env,"APP_ENV=testing\nFACEBOOK_GRAPH_VERSION=v26.0\n");Config::load($env);
$seen=[];$connector=new FacebookConnector(['http_requester'=>function(string $url,array $headers)use(&$seen):array{$seen=[$url,$headers];return['status'=>200,'headers'=>[],'url'=>$url,'body'=>json_encode(['data'=>[['id'=>'123_456','message'=>'Hello from Facebook','created_time'=>'2026-09-20T10:00:00+0000','permalink_url'=>'https://www.facebook.com/123/posts/456','full_picture'=>'https://scontent.example/image.jpg']],'paging'=>['cursors'=>['after'=>'next']]])];}]);
$result=$connector->fetchPosts(['provider_account_id'=>'123','access_token'=>'page-token','account_display_name'=>'Example Page']);
fb_assert(count($result['items'])===1,'Expected one normalized Facebook post');fb_assert($result['items'][0]['external_id']==='123_456','Post ID was not preserved');fb_assert($result['items'][0]['canonical_url']==='https://www.facebook.com/123/posts/456','Permalink was not preserved');fb_assert($result['items'][0]['author']['display_name']==='Example Page','Page name was not preserved');fb_assert(($seen[1]['Authorization']??'')==='Bearer page-token','Page token was not sent as a Bearer header');fb_assert(!str_contains($seen[0],'page-token'),'Page token leaked into URL');
$missing=$connector->fetchPosts(['provider_account_id'=>'123']);fb_assert(isset($missing['error']),'Missing token should return a sync error');
unlink($env);fwrite(STDOUT,"Facebook connector test passed\n");
