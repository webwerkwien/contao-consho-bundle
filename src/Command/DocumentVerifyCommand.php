<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentStore;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiver;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentDelivery;

/**
 * Checks every archived invoice against its hash (8.3) — the check the start
 * page deliberately does not make, because it reads every file.
 *
 * `--retry` archives the documents whose archive FAILED. It does not touch a
 * stored file that has gone missing or changed: writing a regenerated PDF in
 * its place would present a new document as the original, which is the one
 * thing the archive exists to rule out. Those are reported, and the operator
 * restores the file from a backup.
 */
#[AsCommand(
    name: 'consho:document:verify',
    description: 'Checks the archived invoice PDFs against their checksums; --retry archives the failed ones',
)]
#[\Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract(
    // Only with --retry: the PDF into the archive folder and its row.
    writes: true,
    tables: ['tl_consho_document', 'tl_files'],
    trace: [],
    // DocumentArchiver never overwrites an archived file, so the PDF rendered
    // today stays what the archive holds as the original.
    irreversible: 'With --retry: writes the failed invoice PDFs into the archive folder as the archived originals, which are never overwritten afterwards.',
    repeatable: true,
)]
final class DocumentVerifyCommand extends Command
{
    public function __construct(
        private readonly DbalDocumentStore $store,
        private readonly DocumentDelivery $delivery,
        // Undecorated (block R, R13): a retried archive is no second handover
        // to the accounting modules.
        #[Autowire(service: DocumentArchiver::class)]
        private readonly DocumentArchiverInterface $archiver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('retry', null, InputOption::VALUE_NONE, 'Archive the documents whose archive failed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('retry')) {
            foreach ($this->store->idsWithArchive(true) as $id) {
                $this->archiver->archive($id);
            }
        }

        $problems = [];
        $checked = 0;

        foreach ($this->store->idsWithArchive(false) as $id) {
            ++$checked;
            $status = $this->delivery->archiveStatusOf($id);

            if ('ok' !== $status) {
                $problems[] = [(string) $id, (string) ($this->store->find($id)['documentNumber'] ?? ''), $status];
            }
        }

        if ([] === $problems) {
            $io->success(\sprintf('%d archived document(s), all intact.', $checked));

            return Command::SUCCESS;
        }

        $io->table(['Document', 'Number', 'Status'], $problems);
        $io->warning(\sprintf(
            '%d of %d archived document(s) are not intact. "failed": run with --retry once the cause is fixed. "missing"/"changed": restore the file from a backup — it is not regenerated in place.',
            \count($problems),
            $checked,
        ));

        return Command::FAILURE;
    }
}
