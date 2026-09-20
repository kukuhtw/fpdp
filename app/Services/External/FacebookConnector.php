<?php
declare(strict_types=1);
namespace App\Services\External;
use App\Contracts\ExternalContentProviderInterface;
use App\Core\Config;
use App\Core\Http\HttpClient;
final class FacebookConnector implements ExternalContentProviderInterface
{
    public function __construct(private readonly array $configuration=[]){ }
    public function getProviderCode():string{return'FACEBOOK';} public function authenticate(array $configuration):array{return['status'=>'connected','provider'=>'FACEBOOK'];} public function refreshAuthentication(array $account):array{return$account;} public function getProfile(array $account):array{return['provider'=>'FACEBOOK','username'=>$account['provider_account_id']??'page'];}
    public function fetchPosts(array $account,?string $cursor=null):array
    {
        $pageId=(string)($account['provider_account_id']??'');$token=(string)($account['access_token']??'');if($pageId===''||$token==='')return['items'=>[],'next_cursor'=>null,'error'=>'Facebook Page token is unavailable. Reconnect the Page.'];
        $version=(string)Config::get('FACEBOOK_GRAPH_VERSION','v26.0');$url='https://graph.facebook.com/'.rawurlencode($version).'/'.rawurlencode($pageId).'/posts?'.http_build_query(['fields'=>'id,message,story,created_time,permalink_url,full_picture','limit'=>50]);
        try{$response=(new HttpClient())->get($url,['Authorization'=>'Bearer '.$token,'Accept'=>'application/json']);}catch(\Throwable $e){return['items'=>[],'next_cursor'=>null,'error'=>$e->getMessage()];}
        $body=json_decode($response['body'],true);if($response['status']<200||$response['status']>=300||!is_array($body)||isset($body['error']))return['items'=>[],'next_cursor'=>null,'error'=>(string)($body['error']['message']??('Facebook HTTP '.$response['status']))];
        $items=[];foreach((array)($body['data']??[])as$post){if(!is_array($post)||empty($post['id']))continue;$media=empty($post['full_picture'])?[]:[['type'=>'IMAGE','provider'=>'FACEBOOK','url'=>$post['full_picture']]];$items[]=['external_id'=>(string)$post['id'],'type'=>$media?'MEDIA':'NOTE','text'=>(string)($post['message']??$post['story']??''),'media'=>$media,'canonical_url'=>$post['permalink_url']??null,'published_at'=>$post['created_time']??null,'author'=>['display_name'=>$account['account_display_name']??'Facebook Page']];}
        return['items'=>$items,'next_cursor'=>$body['paging']['cursors']['after']??null];
    }
    public function fetchSinglePost(array $account,string $externalPostId):array{return['external_id'=>$externalPostId,'source'=>'FACEBOOK'];} public function disconnect(array $account):bool{return true;}
}
