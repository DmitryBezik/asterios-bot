<?php
require "vendor/autoload.php";

class AsteriosBotManager
{
    public const X5 = 0;
    public const X7 = 8;
    public const URL_X5 = 'https://asterios.tm/index.php?cmd=rss&serv=0&filter=keyboss&out=xml';
    public const URL_X7 = 'https://asterios.tm/index.php?cmd=rss&serv=8&filter=keyboss&out=xml';
    public const CHANNELS = [
        self::X5 => [
            'sub' => '@asteriosx5rb',
            'key' => '@asteriosX5keyRB',
            ],
        self::X7 => [
            'sub' => '@asteriosx7rb',
            'key' => '@asteriosX7keyRB',
            ],
    ];
    public function __construct()
    {
        $dotenv = Dotenv\Dotenv::create(__DIR__);
        $dotenv->load();
        date_default_timezone_set('Europe/Moscow');
    }

    public function getPDO()
    {
        $dbhost = getenv('DB_HOST');
        $dbname = getenv('DB_NAME');
        $dbcharset = getenv('DB_CHARSET');
        $dsn = "mysql:host={$dbhost};dbname={$dbname};charset={$dbcharset}";
        $usr = getenv('DB_USERNAME');
        $pwd = getenv('DB_PASSWORD');

        return new \Slim\PDO\Database($dsn, $usr, $pwd);
    }

    public function getDataPDO(\Slim\PDO\Database $pdo, int $server, int $limit = 20)
    {
        $selectStatement = $pdo->select(['title', 'description', 'timestamp'])
            ->from('new_raids')
            ->where('server', '=', $server)
            ->orderBy('timestamp', 'desc')
            ->limit($limit, 0);

        $stmt = $selectStatement->execute();
        return $stmt->fetchAll();
    }

    public function getRSSData(string $url, int $limit = 20)
    {
        $rss = Feed::loadRss($url);

        $newData = $rss->toArray();
        $remoteBefore = $newData['item'] ?? [];

        return array_map(function ($record) {
            return [
                'title' => $record['title'],
                'description' => $record['description'],
                'timestamp' => $record['timestamp'],
            ];
        }, array_slice($remoteBefore, 0, $limit));
    }

    public function trySend(\Slim\PDO\Database $pdo, array $raid, int $server)
    {
        $date = new DateTime();
        $date->setTimestamp($raid['timestamp']);
        try {
            $insertStatement = $pdo->insert([
                'server',
                'title',
                'description',
                'timestamp'
            ])
                ->into('new_raids')
                ->values([
                    $server,
                    $raid['title'],
                    $raid['description'],
                    $raid['timestamp'],
                ]);
            $insertId = $insertStatement->execute(false);
            $channel = $this->getChannel($raid, $server);
            $text = $date->format('Y-m-d H:i:s') . ' ' . $raid['description'];
            echo $this->send_msg($text, $channel) . PHP_EOL;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            echo "ERROR! $error";
            die();
        }
    }

    public function send_msg($text, $channel)
    {
        $apiToken = getenv('TG_API');

        $data = [
            'chat_id' => $channel,
            'text' => $text
        ];

        return file_get_contents("https://api.telegram.org/bot{$apiToken}/sendMessage?" . http_build_query($data) );
    }

    public function isSubclassRb(string $text)
    {
        return $this->contains($text, [
            'Cabrio',
            'Hallate',
            'Kernon',
            'Golkonda',
        ]);
    }

    public function contains($str, array $raids)
    {
        foreach ($raids as $raid) {
            if (stripos($str, $raid) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $raid
     * @return string
     */
    public function getChannel(array $raid, int $server): string
    {
        return $this->isSubclassRb($raid['title']) ? self::CHANNELS[$server]['sub'] : self::CHANNELS[$server]['key'];
    }
}