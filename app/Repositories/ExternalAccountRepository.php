<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Core\Crypto;
use PDO;
final class ExternalAccountRepository
{
    public function __construct(private readonly PDO $connection) {}
    public function upsertFacebookPage(int $userId, array $page): int
    {
        $find=$this->connection->prepare("SELECT id FROM external_accounts WHERE provider='FACEBOOK' AND external_account_id=:external_id"); $find->execute(['external_id'=>$page['id']]); $id=$find->fetchColumn();
        $values=['user_id'=>$userId,'external_id'=>$page['id'],'username'=>$page['username']??null,'display_name'=>$page['name'],'profile_url'=>$page['link']??('https://www.facebook.com/'.$page['id']),'access_token'=>Crypto::encrypt($page['access_token']),'permissions'=>json_encode($page['tasks']??[])];
        if($id!==false){$s=$this->connection->prepare("UPDATE external_accounts SET user_id=:user_id,external_username=:username,display_name=:display_name,profile_url=:profile_url,access_token=:access_token,permissions=:permissions,connection_status='ACTIVE',updated_at=CURRENT_TIMESTAMP WHERE id=:id");$s->execute([...$values,'id'=>$id]);return(int)$id;}
        $s=$this->connection->prepare("INSERT INTO external_accounts(user_id,provider,external_account_id,external_username,display_name,profile_url,access_token,permissions,connection_status) VALUES(:user_id,'FACEBOOK',:external_id,:username,:display_name,:profile_url,:access_token,:permissions,'ACTIVE')");$s->execute($values);return(int)$this->connection->lastInsertId();
    }
    public function listFacebookByUser(int $userId): array {$s=$this->connection->prepare("SELECT id,external_account_id,external_username,display_name,profile_url,permissions,connection_status,last_sync_at,created_at FROM external_accounts WHERE user_id=:user_id AND provider='FACEBOOK' ORDER BY display_name");$s->execute(['user_id'=>$userId]);return$s->fetchAll();}
    public function deleteFacebookPage(int $userId,int $id): bool {$this->connection->beginTransaction();try{$s=$this->connection->prepare("DELETE FROM external_feed_sources WHERE user_id=:user_id AND provider='FACEBOOK' AND external_account_id=:id");$s->execute(['user_id'=>$userId,'id'=>$id]);$a=$this->connection->prepare("DELETE FROM external_accounts WHERE id=:id AND user_id=:user_id AND provider='FACEBOOK'");$a->execute(['id'=>$id,'user_id'=>$userId]);$ok=$a->rowCount()>0;$this->connection->commit();return$ok;}catch(\Throwable $e){$this->connection->rollBack();throw$e;}}
}
