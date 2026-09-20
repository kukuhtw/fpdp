<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;
use App\Services\External\FacebookIntegrationService;
use App\Services\Visitor\OAuthStateSigner;
use App\Repositories\ExternalAccountRepository;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\UnauthorizedException;
final class FacebookIntegrationController
{
    public function __construct(private readonly AuthService $auth,private readonly FacebookIntegrationService $facebook,private readonly ExternalAccountRepository $accounts,private readonly OAuthStateSigner $stateSigner){}
    public function status(Request $request):Response{$ctx=$this->auth->authenticate($request->bearerToken());return JsonEnvelope::success(['accounts'=>$this->accounts->listFacebookByUser((int)$ctx['user']['id'])]);}
    public function authorize(Request $request):Response{$ctx=$this->auth->authenticate($request->bearerToken());$redirect=$this->callbackUrl($request);$state=$this->stateSigner->sign((string)$ctx['user']['id'],$redirect);return JsonEnvelope::success(['authorization_url'=>$this->facebook->authorizationUrl($state,$redirect)]);}
    public function callback(Request $request):Response
    {
        if(!empty($request->query['error']))throw new UnauthorizedException((string)($request->query['error_description']??'Facebook authorization was denied.'));$code=(string)($request->query['code']??'');$state=(string)($request->query['state']??'');if($code===''||$state==='')throw new UnauthorizedException('Missing Facebook OAuth code or state.');$verified=$this->stateSigner->verify($state);$userId=(int)$verified['handle'];if($userId<1||$verified['redirect_uri']!==$this->callbackUrl($request))throw new UnauthorizedException('Invalid Facebook OAuth state.');$pages=$this->facebook->connectPages($userId,$code,$verified['redirect_uri']);return Response::redirect('/dashboard/integrations?facebook=connected&pages='.count($pages));
    }
    public function disconnect(Request $request,array $params):Response{$ctx=$this->auth->authenticate($request->bearerToken());if(!$this->accounts->deleteFacebookPage((int)$ctx['user']['id'],(int)($params['accountId']??0)))throw new NotFoundException('Facebook Page connection not found.');return Response::noContent();}
    private function callbackUrl(Request $request):string{$scheme=$request->header('x-forwarded-proto')??'http';$host=$request->header('host')??'localhost';return $scheme.'://'.$host.'/api/v1/integrations/facebook/callback';}
}
