<?php

declare(strict_types=1);

namespace Compliance\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;
use Compliance\Model\Entity\PendingApproval;

/**
 * Persistent storage for dual-approval workflow requests.
 *
 * Payload is stored as a JSON string in the `payload` column and transparently
 * encoded/decoded through the custom `json` column type registration — see
 * `Plugin::bootstrap()`.
 *
 * @method \Compliance\Model\Entity\PendingApproval newEmptyEntity()
 * @method \Compliance\Model\Entity\PendingApproval newEntity(array $data, array $options = [])
 * @method \Compliance\Model\Entity\PendingApproval get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \Compliance\Model\Entity\PendingApproval patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \Compliance\Model\Entity\PendingApproval|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \Compliance\Model\Entity\PendingApproval saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 */
class PendingApprovalsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('pending_approvals');
        $this->setPrimaryKey('id');
        $this->setEntityClass(PendingApproval::class);
        $this->addBehavior('Timestamp');
        $schema = $this->getSchema();
        $schema->setColumnType('payload', 'json');
        $this->setSchema($schema);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->notEmptyString('action');
        $validator->notEmptyString('initiator_id');
        $validator->inList('status', ['pending', 'approved', 'rejected']);

        return $validator;
    }
}
