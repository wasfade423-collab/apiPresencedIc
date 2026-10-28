<?php

declare(strict_types=1);

require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/ApiException.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

class Model
{
    private ?MongoDB\Database $database = null;
    private ?Database $connection = null;

    private function db(): MongoDB\Database
    {
        if ($this->database === null) {
            $this->connection = new Database();
            $this->database = $this->connection->getConnection();
        }

        return $this->database;
    }

    private function client(): MongoDB\Client
    {
        $this->connection ??= new Database();

        return $this->connection->getClient();
    }

    private function objectId(string $id): ObjectId
    {
        if (preg_match('/^[a-f0-9]{24}$/i', $id) !== 1) {
            throw new ApiException(400, 'Identifiant MongoDB invalide.');
        }

        return new ObjectId($id);
    }

    private function requiredString(array $data, string $key, int $maxLength = 200): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $maxLength) {
            throw new ApiException(400, sprintf('Le champ "%s" est obligatoire et invalide.', $key));
        }

        return trim($value);
    }

    private function requiredPassword(array $data): string
    {
        $password = $data['password'] ?? null;
        if (!is_string($password) || trim($password) === '' || strlen($password) > 1024) {
            throw new ApiException(400, 'Le champ "password" est obligatoire et invalide.');
        }

        return $password;
    }

    private function documentToArray(mixed $document): array
    {
        if ($document === null) {
            return [];
        }

        return $this->normalize($document);
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof ObjectId) {
            return (string) $value;
        }

        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $this->normalize($item);
            }

            return $result;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalize($item);
            }
        }

        return $value;
    }

    private function findActiveMember(string $id): array
    {
        $member = $this->db()->Membres->findOne([
            '_id' => $this->objectId($id),
            'archived' => ['$ne' => true],
        ]);

        if ($member === null) {
            throw new ApiException(404, 'Membre introuvable.');
        }

        return $this->documentToArray($member);
    }

    private function ensurePoleExists(ObjectId $poleId): void
    {
        if ($this->db()->Poles->findOne(['_id' => $poleId]) === null) {
            throw new ApiException(404, 'Pôle introuvable.');
        }
    }

    private function responsibleMemberIsActive(mixed $responsable): bool
    {
        $memberId = $responsable['membre_id'] ?? null;
        if (!$memberId instanceof ObjectId) {
            return false;
        }

        $member = $this->db()->Membres->findOne([
            '_id' => $memberId,
            'archived' => ['$ne' => true],
        ]);

        return $member !== null
            && isset($responsable['pole_id'], $member['pole_id'])
            && (string) $responsable['pole_id'] === (string) $member['pole_id'];
    }

    private function assertPoleAccess(string $poleId, array $actor): void
    {
        if ($actor['role'] === 'responsable' && ($actor['poleId'] ?? null) !== $poleId) {
            throw new ApiException(403, 'Accès interdit à ce pôle.');
        }
    }

    private function assertUsernameAvailable(string $username, ?string $excludeId = null): void
    {
        foreach (['Admin', 'Responsables'] as $collectionName) {
            $existing = $this->db()->selectCollection($collectionName)->findOne(['username' => $username]);
            if ($existing !== null && ($excludeId === null || strtolower((string) $existing['_id']) !== strtolower($excludeId))) {
                throw new ApiException(409, 'Ce nom d’utilisateur est déjà utilisé.');
            }
        }
    }

    private function newToken(array|BSONDocument $user, string $role): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = new DateTimeImmutable('+8 hours');
        $this->db()->Tokens->createIndex(['expiresAt' => 1], ['expireAfterSeconds' => 0]);
        $this->db()->Tokens->insertOne([
            'tokenHash' => hash('sha256', $token),
            'user_id' => $user['_id'],
            'role' => $role,
            'expiresAt' => new UTCDateTime($expiresAt),
        ]);

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->format(DateTimeInterface::ATOM),
            'user' => [
                'id' => (string) $user['_id'],
                'username' => $user['username'],
                'role' => $role,
            ],
        ];
    }

    public function authenticate(string $token): array
    {
        $tokenHash = hash('sha256', $token);
        $storedToken = $this->db()->Tokens->findOne([
            'tokenHash' => $tokenHash,
            'expiresAt' => ['$gt' => new UTCDateTime(new DateTimeImmutable())],
        ]);

        if ($storedToken === null) {
            throw new ApiException(401, 'Jeton absent, invalide ou expiré.');
        }

        $tokenData = $this->documentToArray($storedToken);
        $role = $tokenData['role'] ?? '';
        $collectionName = $role === 'admin' ? 'Admin' : ($role === 'responsable' ? 'Responsables' : null);
        if ($collectionName === null) {
            throw new ApiException(401, 'Jeton invalide.');
        }

        $user = $this->db()->selectCollection($collectionName)->findOne([
            '_id' => $storedToken['user_id'],
            ...($role === 'responsable' ? ['archived' => ['$ne' => true]] : []),
        ]);

        if ($user === null) {
            throw new ApiException(401, 'Compte introuvable ou désactivé.');
        }

        if ($role === 'responsable' && !$this->responsibleMemberIsActive($user)) {
            throw new ApiException(401, 'Compte introuvable ou désactivé.');
        }

        $userData = $this->documentToArray($user);
        return [
            'id' => $userData['_id'],
            'role' => $role,
            'memberId' => $userData['membre_id'] ?? null,
            'poleId' => $userData['pole_id'] ?? null,
            'tokenHash' => $tokenHash,
        ];
    }

    public function login(array $data): array
    {

        $username = $this->requiredString($data, 'username', 100);
        $password = $this->requiredPassword($data);
        $requestedRole = $data['role'] ?? null;

        if ($requestedRole !== null && !in_array($requestedRole, ['admin', 'responsable'], true)) {
            throw new ApiException(400, 'Le rôle doit être "admin" ou "responsable".');
        }

        $collections = $requestedRole === 'admin'
            ? ['Admin' => 'admin']
            : ($requestedRole === 'responsable'
                ? ['Responsables' => 'responsable']
                : ['Admin' => 'admin', 'Responsables' => 'responsable']);

        $matches = [];
        foreach ($collections as $collectionName => $role) {
            $filter = ['username' => $username];
            if ($role === 'responsable') {
                $filter['archived'] = ['$ne' => true];
            }
            $user = $this->db()->selectCollection($collectionName)->findOne($filter);
            if (
                $user !== null
                && is_string($user['password'] ?? null)
                && password_verify($password, $user['password'])
                && ($role !== 'responsable' || $this->responsibleMemberIsActive($user))
            ) {
                $matches[] = [$user, $role];
            }
        }

        if (count($matches) !== 1) {
            throw new ApiException(401, 'Identifiants invalides.');
        }

        return $this->newToken($matches[0][0], $matches[0][1]);
    }

    public function createResponsible(array $data, array $actor): array
    {
        $memberId = $this->requiredString($data, 'membre_id');
        $member = $this->findActiveMember($memberId);
        $poleId = (string) ($member['pole_id'] ?? '');

        if ($poleId === '') {
            throw new ApiException(409, 'Le membre doit être associé à un pôle avant la création du compte.');
        }
        $this->assertPoleAccess($poleId, $actor);

        if ($this->db()->Responsables->findOne([
            'membre_id' => $this->objectId($memberId),
            'archived' => ['$ne' => true],
        ]) !== null) {
            throw new ApiException(409, 'Ce membre possède déjà un compte responsable actif.');
        }

        $username = $this->requiredString($data, 'username', 100);
        $password = $this->requiredPassword($data);
        if (strlen($password) < 5) {
            throw new ApiException(400, 'Le mot de passe doit contenir au moins 5 caractères.');
        }
        $this->assertUsernameAvailable($username);

        $responsable = [
            'membre_id' => $this->objectId($memberId),
            'pole_id' => $this->objectId($poleId),
            'archived' => false,
            'archived_date' => null,
            'date_debut' => new UTCDateTime(new DateTimeImmutable()),
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT),
        ];
        $result = $this->db()->Responsables->insertOne($responsable);

        return [
            'id' => (string) $result->getInsertedId(),
            'username' => $username,
            'membre_id' => $memberId,
            'pole_id' => $poleId,
            'role' => 'responsable',
            'archived' => false,
            'archived_date' => null,
        ];
    }

    public function listMembers(array $actor): array
    {
        $filter = ['archived' => ['$ne' => true]];
        if ($actor['role'] === 'responsable') {
            $filter['pole_id'] = $this->objectId((string) $actor['poleId']);
        }

        return array_map(
            fn(BSONDocument $member): array => $this->documentToArray($member),
            iterator_to_array($this->db()->Membres->find($filter, ['sort' => ['nom' => 1, 'prenom' => 1]]))
        );
    }

    public function getMember(string $id, array $actor): array
    {
        $member = $this->findActiveMember($id);
        if ($actor['role'] === 'responsable' && ($member['pole_id'] ?? null) !== $actor['poleId']) {
            throw new ApiException(403, 'Accès interdit à ce membre.');
        }

        return $member;
    }

    public function createMember(array $data, array $actor): array
    {
        $nom = $this->requiredString($data, 'nom', 120);
        $prenom = $this->requiredString($data, 'prenom', 120);
        $poleId = $this->requiredString($data, 'pole_id');
        $poleObjectId = $this->objectId($poleId);
        $this->assertPoleAccess($poleId, $actor);
        $this->ensurePoleExists($poleObjectId);

        $result = $this->db()->Membres->insertOne([
            'nom' => $nom,
            'prenom' => $prenom,
            'pole_id' => $poleObjectId,
            'nbre_abs' => 0,
            'archived' => false,
            'archived_date' => null,
        ]);

        return [
            '_id' => (string) $result->getInsertedId(),
            'nom' => $nom,
            'prenom' => $prenom,
            'pole_id' => $poleId,
            'nbre_abs' => 0,
            'archived' => false,
            'archived_date' => null,
        ];
    }

    public function updateMember(string $id, array $data, array $actor): array
    {
        $member = $this->findActiveMember($id);
        if ($actor['role'] === 'responsable' && ($member['pole_id'] ?? null) !== $actor['poleId']) {
            throw new ApiException(403, 'Accès interdit à ce membre.');
        }

        $allowedFields = ['nom', 'prenom'];
        if ($actor['role'] === 'admin') {
            $allowedFields[] = 'pole_id';
        }

        $updates = [];
        foreach ($data as $key => $value) {
            if (!in_array($key, $allowedFields, true)) {
                throw new ApiException(400, sprintf('Le champ "%s" ne peut pas être modifié.', $key));
            }

            if ($key === 'pole_id') {
                $poleId = $this->requiredString($data, 'pole_id');
                $poleObjectId = $this->objectId($poleId);
                $this->ensurePoleExists($poleObjectId);
                $updates[$key] = $poleObjectId;
                continue;
            }

            if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > 120) {
                throw new ApiException(400, sprintf('Le champ "%s" est invalide.', $key));
            }
            $updates[$key] = trim($value);
        }

        if ($updates === []) {
            throw new ApiException(400, 'Aucun champ modifiable fourni.');
        }

        if (isset($updates['pole_id'])) {
            $session = $this->client()->startSession();
            try {
                $session->startTransaction();
                $memberUpdate = $this->db()->Membres->updateOne(
                    ['_id' => $this->objectId($id), 'archived' => ['$ne' => true]],
                    ['$set' => $updates],
                    ['session' => $session]
                );
                if ($memberUpdate->getMatchedCount() !== 1) {
                    throw new ApiException(404, 'Membre introuvable.');
                }
                $this->db()->Responsables->updateMany(
                    ['membre_id' => $this->objectId($id), 'archived' => ['$ne' => true]],
                    ['$set' => ['pole_id' => $updates['pole_id']]],
                    ['session' => $session]
                );
                $session->commitTransaction();
            } catch (Throwable $exception) {
                try {
                    $session->abortTransaction();
                } catch (Throwable $rollbackException) {
                    error_log('Échec de l’annulation de la transaction MongoDB : ' . $rollbackException->getMessage());
                }
                throw $exception;
            } finally {
                $session->endSession();
            }
        } else {
            $this->db()->Membres->updateOne(
                ['_id' => $this->objectId($id), 'archived' => ['$ne' => true]],
                ['$set' => $updates]
            );
        }

        return $this->getMember($id, $actor);
    }

    public function deleteMember(string $id): array
    {
        $archivedAt = new UTCDateTime(new DateTimeImmutable());
        $result = $this->db()->Membres->updateOne(
            ['_id' => $this->objectId($id), 'archived' => ['$ne' => true]],
            ['$set' => ['archived' => true, 'archived_date' => $archivedAt]]
        );

        if ($result->getModifiedCount() !== 1) {
            throw new ApiException(404, 'Membre introuvable.');
        }

        return [
            'id' => $id,
            'archived' => true,
            'archived_date' => $this->normalize($archivedAt),
        ];
    }

    public function listPoles(array $actor): array
    {
        $filter = [];
        if ($actor['role'] === 'responsable') {
            $filter['_id'] = $this->objectId((string) $actor['poleId']);
        }

        $poles = [];
        foreach ($this->db()->Poles->find($filter, ['sort' => ['nom' => 1]]) as $pole) {
            $poleData = $this->documentToArray($pole);
            $poleData['nbreMembre'] = $this->db()->Membres->countDocuments([
                'pole_id' => $pole['_id'],
                'archived' => ['$ne' => true],
            ]);
            $poles[] = $poleData;
        }

        return $poles;
    }

    public function listPoleMembers(string $poleId, array $actor): array
    {
        $poleObjectId = $this->objectId($poleId);
        $this->assertPoleAccess($poleId, $actor);
        $this->ensurePoleExists($poleObjectId);

        return array_map(
            fn(BSONDocument $member): array => $this->documentToArray($member),
            iterator_to_array($this->db()->Membres->find([
                'pole_id' => $poleObjectId,
                'archived' => ['$ne' => true],
            ], ['sort' => ['nom' => 1, 'prenom' => 1]]))
        );
    }

    public function createPresence(array $data, array $actor): array
    {
        $poleId = $this->requiredString($data, 'id_pole');
        $poleObjectId = $this->objectId($poleId);
        $this->assertPoleAccess($poleId, $actor);
        $this->ensurePoleExists($poleObjectId);

        $date = $this->requiredString($data, 'date_seance', 80);
        if (!preg_match(
            '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})?)?$/i',
            $date
        )) {
            throw new ApiException(400, 'Le champ "date_seance" doit être une date ISO 8601 valide.');
        }
        try {
            $dateTime = new DateTimeImmutable($date);
        } catch (Exception) {
            throw new ApiException(400, 'Le champ "date_seance" doit être une date valide.');
        }

        $absentIds = $data['liste_abs'] ?? [];
        if (!is_array($absentIds) || !array_is_list($absentIds)) {
            throw new ApiException(400, 'Le champ "liste_abs" doit être un tableau d’identifiants.');
        }
        foreach ($absentIds as $absentId) {
            if (!is_string($absentId)) {
                throw new ApiException(400, 'Chaque identifiant de "liste_abs" doit être une chaîne.');
            }
        }

        $absentObjectIds = [];
        foreach (array_unique($absentIds) as $absentId) {
            $member = $this->findActiveMember($absentId);
            if (($member['pole_id'] ?? null) !== $poleId) {
                throw new ApiException(400, 'Tous les membres absents doivent appartenir au pôle de la séance.');
            }
            $absentObjectIds[] = $this->objectId($absentId);
        }

        $authorId = $actor['id'] ?? null;
        if (!is_string($authorId) || $authorId === '') {
            throw new ApiException(403, 'Le compte connecté ne possède pas un identifiant valide.');
        }

        $presence = [
            'id_pole' => $poleObjectId,
            'date_seance' => new UTCDateTime($dateTime),
            'liste_abs' => $absentObjectIds,
            'auteur' => $this->objectId($authorId),
        ];
        if ($this->db()->Presences->findOne([
            'id_pole' => $poleObjectId,
            'date_seance' => $presence['date_seance'],
        ]) !== null) {
            throw new ApiException(409, 'Une séance existe déjà pour ce pôle à cette date.');
        }

        $session = $this->client()->startSession();
        try {
            $session->startTransaction();
            $result = $this->db()->Presences->insertOne($presence, ['session' => $session]);
            foreach ($absentObjectIds as $absentObjectId) {
                $update = $this->db()->Membres->updateOne(
                    ['_id' => $absentObjectId, 'archived' => ['$ne' => true]],
                    ['$inc' => ['nbre_abs' => 1]],
                    ['session' => $session]
                );
                if ($update->getMatchedCount() !== 1) {
                    throw new ApiException(409, 'Un membre est devenu indisponible pendant l’enregistrement.');
                }
            }
            $session->commitTransaction();
        } catch (Throwable $exception) {
            try {
                $session->abortTransaction();
            } catch (Throwable $rollbackException) {
                error_log('Échec de l’annulation de la transaction MongoDB : ' . $rollbackException->getMessage());
            }
            throw $exception;
        } finally {
            $session->endSession();
        }

        return [
            '_id' => (string) $result->getInsertedId(),
            'id_pole' => $poleId,
            'date_seance' => $dateTime->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
            'liste_abs' => array_map('strval', $absentObjectIds),
            'auteur' => $authorId,
        ];
    }

    private function presenceMemberDetails(mixed $memberId): array
    {
        $id = (string) $memberId;
        $member = $this->db()->Membres->findOne(['_id' => $this->objectId($id)]);

        return [
            'id' => $id,
            'nom' => $member['nom'] ?? null,
            'prenom' => $member['prenom'] ?? null,
        ];
    }

    private function presenceAuthorDetails(mixed $authorId): array
    {
        $id = (string) $authorId;
        $objectId = $this->objectId($id);

        // Older presence records store the member ID directly as the author.
        $member = $this->db()->Membres->findOne(['_id' => $objectId]);
        if ($member !== null) {
            return [
                'id' => $id,
                'nom' => $member['nom'] ?? null,
                'prenom' => $member['prenom'] ?? null,
            ];
        }

        $responsable = $this->db()->Responsables->findOne(['_id' => $objectId]);
        if ($responsable !== null) {
            $memberId = $responsable['membre_id'] ?? null;
            if ($memberId instanceof ObjectId) {
                $member = $this->db()->Membres->findOne(['_id' => $memberId]);

                return [
                    'id' => $id,
                    'nom' => $member['nom'] ?? null,
                    'prenom' => $member['prenom'] ?? null,
                ];
            }

            return [
                'id' => $id,
                'nom' => $responsable['username'] ?? null,
                'prenom' => '',
            ];
        }

        $admin = $this->db()->Admin->findOne(['_id' => $objectId]);

        return [
            'id' => $id,
            'nom' => $admin['username'] ?? null,
            'prenom' => '',
        ];
    }

    public function listPresencesForPole(string $poleId, array $actor): array
    {
        $poleObjectId = $this->objectId($poleId);
        $this->assertPoleAccess($poleId, $actor);
        $this->ensurePoleExists($poleObjectId);
        $pole = $this->db()->Poles->findOne(['_id' => $poleObjectId]);
        $presences = [];

        foreach ($this->db()->Presences->find(
            ['id_pole' => $poleObjectId],
            ['sort' => ['date_seance' => -1]]
        ) as $presence) {
            $presenceData = $this->documentToArray($presence);
            $absenceDetails = [];
            foreach ($presence['liste_abs'] ?? [] as $memberId) {
                $absenceDetails[] = $this->presenceMemberDetails($memberId);
            }

            $presenceData['liste_abs'] = $absenceDetails;
            $presenceData['auteur'] = isset($presence['auteur'])
                ? $this->presenceAuthorDetails($presence['auteur'])
                : null;
            $presences[] = $presenceData;
        }

        return [
            'id_pole' => $poleId,
            'nom_pole' => $pole['nom'] ?? null,
            'presences' => $presences,
        ];
    }

    public function listAllPresences(array $actor): array
    {
        if ($actor['role'] !== 'admin') {
            throw new ApiException(403, 'Cette opération est réservée à l’administrateur.');
        }

        return array_map(
            fn(BSONDocument $presence): array => $this->documentToArray($presence),
            iterator_to_array($this->db()->Presences->find([], ['sort' => ['date_seance' => -1]]))
        );
    }

    public function updateUser(string $id, array $data, array $actor): array
    {
        $userId = $this->objectId($id);
        $adminUser = $this->db()->Admin->findOne(['_id' => $userId]);
        $collectionName = $adminUser !== null ? 'Admin' : 'Responsables';
        $user = $adminUser ?? $this->db()->Responsables->findOne([
            '_id' => $userId,
            'archived' => ['$ne' => true],
        ]);

        if ($user === null) {
            throw new ApiException(404, 'Compte introuvable.');
        }

        if (
            $actor['role'] !== 'admin'
            && ($collectionName !== 'Responsables' || strtolower($actor['id']) !== strtolower($id))
        ) {
            throw new ApiException(403, 'Vous ne pouvez modifier que votre propre compte.');
        }

        $updates = [];
        if (array_key_exists('username', $data)) {
            $username = $this->requiredString($data, 'username', 100);
            $this->assertUsernameAvailable($username, $id);
            $updates['username'] = $username;
        }
        if (array_key_exists('password', $data)) {
            $password = $this->requiredPassword($data);
            if (strlen($password) < 5) {
                throw new ApiException(400, 'Le mot de passe doit contenir au moins 5 caractères.');
            }
            $updates['password'] = password_hash($password, PASSWORD_DEFAULT);
        }
        if (count($updates) !== count($data) || $updates === []) {
            throw new ApiException(400, 'Seuls les champs "username" et "password" peuvent être modifiés.');
        }

        $this->db()->selectCollection($collectionName)->updateOne(['_id' => $userId], ['$set' => $updates]);

        if (array_key_exists('password', $updates)) {
            $userRole = $collectionName === 'Admin' ? 'admin' : 'responsable';
            $tokenFilter = [
                'user_id' => $userId,
                'role' => $userRole,
            ];
            if (
                ($actor['role'] ?? null) === $userRole
                && strtolower((string) ($actor['id'] ?? '')) === strtolower($id)
                && is_string($actor['tokenHash'] ?? null)
            ) {
                $tokenFilter['tokenHash'] = ['$ne' => $actor['tokenHash']];
            }
            $this->db()->Tokens->deleteMany($tokenFilter);
        }

        return [
            'id' => $id,
            'username' => $updates['username'] ?? $user['username'],
            'role' => $collectionName === 'Admin' ? 'admin' : 'responsable',
        ];
    }

    public function deleteResponsable(string $id): array
    {
        $archivedAt = new UTCDateTime(new DateTimeImmutable());
        $result = $this->db()->Responsables->updateOne(
            ['_id' => $this->objectId($id), 'archived' => ['$ne' => true]],
            ['$set' => ['archived' => true, 'archived_date' => $archivedAt]]
        );

        if ($result->getModifiedCount() !== 1) {
            throw new ApiException(404, 'Responsable introuvable.');
        }

        return [
            'id' => $id,
            'archived' => true,
            'archived_date' => $this->normalize($archivedAt),
        ];
    }
}
