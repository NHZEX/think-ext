<?php

namespace Zxin\Think\Ext\Certificate;

use Composer\CaBundle\CaBundle;
use Phar;
use RuntimeException;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use Zxin\Think\Ext\ExtConfig;
use function class_exists;
use function curl_close;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function date;
use function file_exists;
use function file_put_contents;
use function filemtime;
use function hash_file;
use function realpath;
use function substr;
use function touch;

class CertificateCommand extends Command
{
    public const CA_CHECKSUM_URL = 'https://curl.haxx.se/ca/cacert.pem.sha256';
    public const CA_FILE_URL = 'https://curl.haxx.se/ca/cacert.pem';

    public function configure()
    {
        $this
            ->setName('ext:cert:update')
            ->setDescription('更新CA文件')
            ->addOption(
                'force',
                'F',
                Option::VALUE_NONE,
                '强制覆盖'
            );
    }

    protected function getCaFilePath(): string
    {
        if ((bool) Phar::running(false)) {
            $path = ExtConfig::getInstance()->getBuiltCaFilePath();
            if (null === $path) {
                throw new RuntimeException('not support phar');
            } else {
                return $path;
            }
        } else {
            return CaBundle::getBundledCaBundlePath();
        }
    }

    /**
     * @param Input  $input
     * @param Output $output
     * @return int
     */
    public function execute(Input $input, Output $output): int
    {
        $input->getOption('force');

        if (!class_exists(CaBundle::class)) {
            $output->error("Missing package composer/ca-bundle");
            return 1;
        }
        $caFile = realpath($this->getCaFilePath());

        if (false === file_exists($caFile)) {
            $output->error("Missing certificate {$caFile}");
            return 1;
        }

        $this->output->info("内建CA文件：{$caFile}");
        $this->checkAndUpdateCertificate($caFile);

        return 0;
    }

    /**
     * @param string|null $saveChecksumPath
     * @return string
     */
    protected function updateCertificateSha256(?string $saveChecksumPath = null): string
    {
        $remoteFileTime = 0;
        $checksumFile = $this->curlGet(self::CA_CHECKSUM_URL, [], $remoteFileTime);
        // 写出校验和文件
        if (null !== $saveChecksumPath) {
            file_put_contents($saveChecksumPath, $checksumFile);
            // 同步文件时间
            $remoteFileTime > 0 && touch($saveChecksumPath, $remoteFileTime);
        }
        // 提取 sha256 部分
        $sha256 = substr($checksumFile, 0, 64);

        $this->output->info('获取CA_SHA文件');
        $this->output->info('  - 最后更新时间：' . date('Y-m-d H:i:s', $remoteFileTime));
        $this->output->info('  - 最新CA检验值：' . $sha256);

        return $sha256;
    }

    /**
     * 检查并更新CA根证书
     * @param string $caFile
     * @return bool
     */
    public function checkAndUpdateCertificate(string $caFile): bool
    {
        // $caFile . '.sha256'
        $latestSha256 = $this->updateCertificateSha256();
        $localSha256 = hash_file('sha256', $caFile);

        if ($localSha256 !== $latestSha256) {
            $remoteFileTime = 0;
            $this->curlGet(self::CA_FILE_URL, [
                CURLOPT_HEADER => true,
                CURLOPT_NOBODY => true,
            ], $remoteFileTime);

            $this->output->info('获取CA_ROOT文件');
            $this->output->info('  - 最后更新时间：' . date('Y-m-d H:i:s', $remoteFileTime));
            $this->output->info('  - 本地文件时间：' . date('Y-m-d H:i:s', filemtime($caFile)));
            $this->output->info('  - 本地CA检验值：' . $localSha256);

            $ca_file = $this->curlGet(self::CA_FILE_URL, [], $remoteFileTime);
            // 写出证书文件
            file_put_contents($caFile, $ca_file);
            // 同步文件时间
            $remoteFileTime > 0 && touch($caFile, $remoteFileTime);

            $this->output->info('CA更新成功');
        } else {
            $this->output->info('CA无需更新');
            return true;
        }
        return true;
    }

    protected function curlGet(string $url, array $opts, int &$remoteFileTime = 0): string
    {
        $opts += [
            CURLOPT_URL => $url,
            // 重定向相关
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            // 以变量输出返回值
            CURLOPT_RETURNTRANSFER => true,
            // 输出HTTP协议头
            CURLOPT_HEADER => false,
            // 不传输内容
            CURLOPT_NOBODY => false,
            // 尝试获取远程文档修改时间
            CURLOPT_FILETIME => true,
            // 证书相关
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO => CaBundle::getSystemCaRootBundlePath(),
        ];
        $ch = curl_init();
        curl_setopt_array($ch, $opts);
        $result = curl_exec($ch);
        if (0 !== curl_errno($ch)) {
            throw new RuntimeException('请求失败：' . curl_error($ch));
        }
        $remoteFileTime = (int) curl_getinfo($ch, CURLINFO_FILETIME);
        curl_close($ch);
        return (string) $result;
    }
}
