<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

/**
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @author Dmttro Naumenko <d.naumenko.a@gmail.com>
 */
class Subsplit
{
    const GITHUB_BASE_URL = 'https://api.github.com';

    public $git;
    public $repo;
    public $root;
    public $subsplits;
    public $branches;
    public $githubToken;
    public $lastSync;

    public function __construct($root, array $branches, array $subsplits, $githubToken, $git = 'git')
    {
        $this->root = $root;
        $this->subsplits = $subsplits;
        $this->branches = $branches;
        $this->githubToken = $githubToken;
        $this->git = $git;

        $syncFile = $this->getSyncFile();
        if (is_file($syncFile)) {
            $this->lastSync = require($syncFile);
        } else {
            $this->lastSync = array('hashes' => array(), 'subsplits' => array());
        }
    }

    private function getSubtreeWorkDir(string $repo): string
    {
        return "{$this->root}/.subsplit-" . str_replace('/', '-', $repo) . '/';
    }

    private function flushSubtreeCache(string $workDir): void
    {
        $subtreeCache = "$workDir/.git/subtree-cache";
        if (is_dir($subtreeCache)) {
            $command = "rm -rf $subtreeCache";
            echo "Executing command: $command\n";
            $return = 0;
            passthru($command, $return);
            if ($return != 0) {
                throw new Exception("Flushing subtree cache failed (return value $return).");
            }
        }
    }

    public function update($tag = null)
    {
        foreach ($this->branches as $repo => $branches) {
            $workDir = $this->getSubtreeWorkDir($repo);
            if (!is_dir("$workDir/.git")) {
                throw new Exception("No git repo was found in '$workDir'. You probably should init it by running: git subsplit init git@github.com:$repo --work-dir=\"$workDir\"");
            }

            foreach ($branches as $srcBranch => $dstBranch) {
                $lastHash = $this->lastSync['hashes'][$repo][$srcBranch] ?? false;
                $hash = $this->getHash($repo, $srcBranch);

                if ($lastHash === false || $tag !== null) {
                    $splits = $this->subsplits[$repo];
                } elseif ($lastHash !== $hash) {
                    $splits = $this->getChangedSubsplits($repo, $hash, $lastHash);
                } else {
                    $splits = array();
                }

                foreach ($this->subsplits[$repo] as $path => $splitConfig) {
                    $dstRepo = $splitConfig['repo'];
                    if (!isset($this->lastSync['subsplits'][$dstRepo][$path])) {
                        $splits[$path] = $splitConfig;
                    }
                }

                if (!empty($splits)) {
                    $this->updateSubsplits($repo, $srcBranch, $dstBranch, $splits, $hash, $tag);
                } else {
                    echo "No updates found on branch: $branch\n";
                }
            }
        }
    }

    protected function getChangedSubsplits(string $repo, $hash, $lastHash)
    {
        $diff = $this->queryGithub("/repos/$repo/compare/$lastHash...$hash");
        $subsplits = array();
        foreach ($diff['files'] as $file) {
            $filename = $file['filename'];
            foreach ($this->subsplits[$repo] as $path => $config) {
                if (strpos($filename, $path) === 0) {
                    $subsplits[$path] = $config;
                }
            }
        }
        return $subsplits;
    }

    protected function updateSubsplits(string $repo, $srcBranch, $dstBranch, $subsplits, $hash, $tag): void
    {
        $splits = [];
        $treeFilter = null;
        foreach ($subsplits as $path => $config) {
            if (!isset($treeFilter)) {
                $treeFilter = $config['treeFilter'] ?? false;
            } elseif ($treeFilter !== $config['treeFilter']) {
                throw new \Exception('Different treeFilters in the same source repo are not supporeted yet. (But should be easy to implement - just change foreaches order ;) )');
            }

            $splits[] = "$path:git@github.com:{$config['repo']}.git";
        }

        $commands[] = "cd {$this->root}";

        $branch = "$srcBranch:$dstBranch";
        $pattern = sprintf('%s subsplit publish "%s" --work-dir="%s" --update', $this->git, implode(' ', $splits), $this->getSubtreeWorkDir($repo));
        if ($tag === '*') {
            $pattern .= sprintf(' --heads="%s"', $branch);
        } elseif ($tag === null) {
            $pattern .= sprintf(' --heads="%s" --no-tags', $branch);
        } else {
            $pattern .= sprintf(' --no-heads --tags="%s"', $tag);
        }
        if ($treeFilter) {
            $pattern .= sprintf(' --tree-filter=%s', escapeshellarg($treeFilter));
        }
        $commands[] = $pattern;

        $command = implode(' && ', $commands);
        echo "Executing command: {$command}\n";
        $return = 0;
        passthru($command, $return);
        if ($return != 0) {
            throw new Exception("Subsplit publish failed (return value $return).");
        }

        $this->lastSync['subsplits'][$repo] = array_merge($this->lastSync['subsplits'][$repo] ?? [], $subsplits);
        $this->lastSync['hashes'][$repo][$srcBranch] = $hash;
        $syncFile = $this->getSyncFile();
        file_put_contents($syncFile, "<?php\n\nreturn " . var_export($this->lastSync, true) . ";\n");
    }

    protected function getSyncFile()
    {
        return $this->root . '/last-sync.php';
    }

    protected function getHash(string $repo, string $branch): string
    {
        $data = $this->queryGithub("/repos/$repo/branches/$branch");
        if (isset($data['commit']['sha'])) {
            return $data['commit']['sha'];
        } else {
            throw new Exception("Unknown data received: " . var_export($data, true));
        }
    }

    protected function queryGithub($url)
    {
        $url = self::GITHUB_BASE_URL . $url;
        $c = curl_init();
                curl_setopt($c, CURLOPT_SSLVERSION, 6);
        curl_setopt($c, CURLOPT_URL, $url);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($c, CURLOPT_TIMEOUT, 5);
        curl_setopt($c, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US; rv:1.9.1.2) Gecko/20090729 Firefox/3.5.2 GTB5');
        curl_setopt($c, CURLOPT_HTTPHEADER, array(
            "Authorization: token {$this->githubToken}",
            'Accept: application/json'
        ));
        $response = curl_exec($c);
        $responseInfo = curl_getinfo($c);
        curl_close($c);
        if (intval($responseInfo['http_code']) == 200) {
            return json_decode($response, true);
        } else {
            throw new Exception("Failed to fetch URL: $url (error code {$responseInfo['http_code']}): " . json_encode($responseInfo));
        }
    }
}
