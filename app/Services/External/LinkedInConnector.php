<?php
declare(strict_types=1);
namespace App\Services\External;
use App\Contracts\ExternalContentProviderInterface;use App\Core\Config;use App\Core\Http\HttpClient;use Closure;
final class LinkedInConnector implements ExternalContentProviderInterface
{
    private readonly Closure $requester;
    public function __construct(private readonly array $configuration=[]){$r=$configuration['http_requester']??null;$this->requester=$r instanceof Closure?$r:static fn(string $url,array $headers):array=>(new HttpClient())->get($url,$headers);}
    public function getProviderCode():string{return'LINKEDIN';}public function authenticate(array $configuration):array{return['status'=>'connected','provider'=>'LINKEDIN'];}public function refreshAuthentication(array $account):array{return$account;}public function getProfile(array $account):array{return['provider'=>'LINKEDIN','username'=>$account['provider_account_id']??'organization'];}
    public function fetchPosts(array $account,?string $cursor=null):array
    {
        $id=(string)($account['provider_account_id']??'');$token=(string)($account['access_token']??'');if($id===''||$token==='')return['items'=>[],'next_cursor'=>null,'error'=>'LinkedIn token is unavailable. Reconnect the organization.'];$version=(string)Config::get('LINKEDIN_VERSION','202609');$url='https://api.linkedin.com/rest/posts?'.http_build_query(['author'=>'urn:li:organization:'.$id,'q'=>'author','count'=>50,'sortBy'=>'LAST_MODIFIED']);$headers=['Authorization'=>'Bearer '.$token,'Accept'=>'application/json','LinkedIn-Version'=>$version,'X-Restli-Protocol-Version'=>'2.0.0'];
        try{$response=($this->requester)($url,$headers);}catch(\Throwable $e){return['items'=>[],'next_cursor'=>null,'error'=>$e->getMessage()];}$body=json_decode($response['body'],true);if($response['status']<200||$response['status']>=300||!is_array($body)||isset($body['message']))return['items'=>[],'next_cursor'=>null,'error'=>(string)($body['message']??('LinkedIn HTTP '.$response['status']))];$items=[];
        foreach((array)($body['elements']??[])as$post){if(!is_array($post)||empty($post['id']))continue;$urn=(string)$post['id'];$published=isset($post['publishedAt'])?gmdate('c',(int)$post['publishedAt']/1000):null;$items[]=['external_id'=>$urn,'type'=>'NOTE','text'=>(string)($post['commentary']??''),'media'=>[],'canonical_url'=>'https://www.linkedin.com/feed/update/'.$urn,'published_at'=>$published,'author'=>['display_name'=>$account['account_display_name']??'LinkedIn Organization']];}
        return['items'=>$items,'next_cursor'=>$body['paging']['start']??null];
    }
    public function fetchSinglePost(array $account,string $externalPostId):array{return['external_id'=>$externalPostId,'source'=>'LINKEDIN'];}public function disconnect(array $account):bool{return true;}
}
