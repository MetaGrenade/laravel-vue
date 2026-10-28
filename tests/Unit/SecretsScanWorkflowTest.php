<?php

namespace Tests\Unit;

use Tests\TestCase;

class SecretsScanWorkflowTest extends TestCase
{
    public function test_the_secrets_job_reads_the_whole_branch_and_checks_that_it_can_see_a_key(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/security.yml'));

        $this->assertStringContainsString('gitleaks detect --source . --redact --exit-code 1 --log-opts="--full-history HEAD"', $workflow);
        $this->assertStringContainsString('The secrets scan accepted a key.', $workflow);
        $this->assertStringNotContainsString('gitleaks-action', $workflow);
        $this->assertStringNotContainsString('log-opts=-1', $workflow);
        $this->assertStringNotContainsString('--log-opts=--all', $workflow);
        $this->assertStringContainsString('\101\113\111\101\062\063\064\065\066\067\070\071\102\103\104\105\106\107\110\112', $workflow);
        $this->assertStringNotContainsString('key="${key}I"', $workflow);
        $this->assertStringNotContainsString('AKIA', $workflow);
    }
}
