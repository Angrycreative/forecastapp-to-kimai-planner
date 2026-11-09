<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Controller;

use App\Controller\AbstractController;
use KimaiPlugin\ForecastImportBundle\Entity\Mapping;
use KimaiPlugin\ForecastImportBundle\Forecast\ForecastApiException;
use KimaiPlugin\ForecastImportBundle\Forecast\ForecastCredentials;
use KimaiPlugin\ForecastImportBundle\Form\ConnectForm;
use KimaiPlugin\ForecastImportBundle\Form\MappingData;
use KimaiPlugin\ForecastImportBundle\Form\PersonMappingForm;
use KimaiPlugin\ForecastImportBundle\Form\ProjectMappingForm;
use KimaiPlugin\ForecastImportBundle\Form\TentativeTagsForm;
use KimaiPlugin\ForecastImportBundle\Import\ForecastImport;
use KimaiPlugin\ForecastImportBundle\Import\KimaiCatalogue;
use KimaiPlugin\ForecastImportBundle\Import\MappingService;
use KimaiPlugin\ForecastImportBundle\Forecast\ForecastData;
use KimaiPlugin\ForecastImportBundle\Import\Plan;
use KimaiPlugin\ForecastImportBundle\Import\Preview;
use KimaiPlugin\ForecastImportBundle\Import\ReadSummary;
use KimaiPlugin\ForecastImportBundle\Import\ProjectSuggester;
use KimaiPlugin\ForecastImportBundle\Import\NoteOptions;
use KimaiPlugin\ForecastImportBundle\Import\Target;
use KimaiPlugin\ForecastImportBundle\Import\TokenVault;
use KimaiPlugin\ForecastImportBundle\Repository\ImportedPlanningRepository;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The import screens: connect and fetch, map people, map projects, choose tentative tags, preview and import.
 */
#[Route(path: '/admin/forecast-import')]
#[IsGranted('import_forecast_planner')]
final class ForecastImportController extends AbstractController
{
    private const SESSION_READ = 'forecast_import.read';
    private const SESSION_IMPORTED = 'forecast_import.imported';

    public function __construct(
        private readonly ForecastImport $import,
        private readonly MappingService $mappings,
        private readonly ImportedPlanningRepository $links,
        private readonly KimaiCatalogue $catalogue,
        private readonly TranslatorInterface $translator,
        private readonly TokenVault $vault,
    ) {
    }

    #[Route(path: '', name: 'forecast_import', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $data = $this->import->data();
        // the dates of the last read, so reading again picks up where you were; today to a year ahead the first time
        $saved = $this->vault->saved();
        $notes = $this->mappings->noteOptions();
        $form = $this->createForm(ConnectForm::class, [
            'projectNotes' => $notes->projectNotes,
            'assignmentNotes' => $notes->assignmentNotes,
            'accountId' => $data?->accountId ?? $saved?->getAccountId(),
            'begin' => $data !== null ? \DateTime::createFromImmutable($data->begin) : new \DateTime('today'),
            'end' => $data !== null ? \DateTime::createFromImmutable($data->end) : new \DateTime('today +12 months'),
            'remember' => true,
        ], ['action' => $this->generateUrl('forecast_import'), 'token_saved' => $saved !== null]);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $values = $form->getData();
            $accountId = (string) $values['accountId'];
            $typed = trim((string) $values['token']);
            try {
                if ($typed !== '') {
                    $credentials = new ForecastCredentials($accountId, $typed);
                } else {
                    // no token typed: the saved one, for the account it was saved for
                    $credentials = $this->vault->load();
                    if ($credentials === null || $credentials->accountId !== $accountId) {
                        $credentials = null;
                        $form->get('token')->addError(new FormError($this->translator->trans('forecast_import.token_needed')));
                    }
                }
                if ($credentials !== null) {
                    $read = $this->import->fetch(
                        $credentials,
                        \DateTimeImmutable::createFromMutable($values['begin'])->setTime(0, 0),
                        \DateTimeImmutable::createFromMutable($values['end'])->setTime(0, 0),
                    );
                    $this->mappings->saveNoteOptions(new NoteOptions((bool) $values['projectNotes'], (bool) $values['assignmentNotes']));
                    // a typed token is kept only once Forecast has accepted it, and only when asked to
                    if ($typed !== '') {
                        $values['remember'] ? $this->vault->save($credentials) : $this->vault->clear();
                    }
                    $request->getSession()->set(self::SESSION_READ, $read->toArray());

                    return $this->redirectToRoute('forecast_import');
                }
            } catch (ForecastApiException $exception) {
                $this->flashError('forecast_import.fetch_failed', $exception->getMessage());
            }
        }

        $read = $request->getSession()->remove(self::SESSION_READ);

        return $this->render('@ForecastImport/index.html.twig', [
            'form' => $form->createView(),
            'data' => $data,
            'read' => \is_array($read) && $data !== null ? ReadSummary::fromArray($read) : null,
            'steps' => $this->steps($data, 'read'),
            'savedToken' => $this->vault->saved(),
            'imported' => $this->links->countPlannings(),
            'capabilities' => $this->import->capabilities(),
        ]);
    }

    #[Route(path: '/people', name: 'forecast_import_people', methods: ['GET'])]
    public function people(): Response
    {
        $data = $this->import->data();
        if ($data === null) {
            return $this->redirectToRoute('forecast_import');
        }

        return $this->render('@ForecastImport/people.html.twig', [
            'steps' => $this->steps($data, 'people'),
            'data' => $data,
            'rows' => $this->mappings->personRows($data),
        ]);
    }

    #[Route(path: '/people/{kind}/{id}', name: 'forecast_import_edit_person', requirements: ['kind' => 'person|placeholder', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function editPerson(string $kind, int $id, Request $request): Response
    {
        $data = $this->import->data();
        $row = $data === null ? null : $this->findRow($this->mappings->personRows($data), $id, $kind);
        if ($row === null) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(PersonMappingForm::class, MappingData::fromTarget($row['target'], Target::USER), [
            'action' => $this->generateUrl('forecast_import_edit_person', ['kind' => $kind, 'id' => $id]),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->mappings->save($kind, $id, $form->getData()->toTarget());

            return $this->saved($request, 'forecast_import_people');
        }

        return $this->render('@ForecastImport/edit.html.twig', [
            'form' => $form->createView(),
            'title' => $row['name'],
            'subtitle' => $row['email'],
            'assignments' => $row['assignments'],
            'back' => $this->generateUrl('forecast_import_people'),
            'resetUrl' => $this->generateUrl('forecast_import_reset', ['kind' => $kind, 'id' => $id]),
        ]);
    }

    #[Route(path: '/people/{kind}/{id}/row', name: 'forecast_import_person_row', requirements: ['kind' => 'person|placeholder', 'id' => '\d+'], methods: ['GET'])]
    public function personRow(string $kind, int $id): Response
    {
        $data = $this->import->data();
        $row = $data === null ? null : $this->findRow($this->mappings->personRows($data), $id, $kind);
        if ($row === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('@ForecastImport/_person_row.html.twig', ['row' => $row]);
    }

    #[Route(path: '/projects/{id}/row', name: 'forecast_import_project_row', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function projectRow(int $id): Response
    {
        $data = $this->import->data();
        $row = $data === null ? null : ($this->mappings->projectRows($data, true, $id)[0] ?? null);
        if ($row === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('@ForecastImport/_project_row.html.twig', ['row' => $row, 'confident' => ProjectSuggester::CONFIDENT_SCORE]);
    }

    #[Route(path: '/steps/{active}', name: 'forecast_import_steps', requirements: ['active' => 'read|people|projects|tentative|preview'], methods: ['GET'])]
    public function stepBar(string $active): Response
    {
        return $this->render('@ForecastImport/_steps.html.twig', ['steps' => $this->steps($this->import->data(), $active)]);
    }

    #[Route(path: '/projects', name: 'forecast_import_projects', methods: ['GET'])]
    public function projects(): Response
    {
        $data = $this->import->data();
        if ($data === null) {
            return $this->redirectToRoute('forecast_import');
        }

        return $this->render('@ForecastImport/projects.html.twig', [
            'steps' => $this->steps($data, 'projects'),
            'data' => $data,
            'rows' => $this->mappings->projectRows($data),
            'confident' => ProjectSuggester::CONFIDENT_SCORE,
        ]);
    }

    #[Route(path: '/projects/accept', name: 'forecast_import_accept', methods: ['POST'])]
    public function acceptSuggestions(Request $request): Response
    {
        $data = $this->import->data();
        if ($data === null || !$this->isCsrfTokenValid('forecast_import_accept', (string) $request->request->get('_token'))) {
            $this->flashError('forecast_import.expired');

            return $this->redirectToRoute('forecast_import_projects');
        }

        $selected = array_map('intval', (array) $request->request->all('projects'));
        $accepted = $this->mappings->acceptSuggestions($data, $selected, ProjectSuggester::MINIMUM_SCORE);
        $this->addFlash('success', $this->translator->trans('forecast_import.accepted', ['%count%' => $accepted], 'flashmessages'));

        return $this->redirectToRoute('forecast_import_projects');
    }

    #[Route(path: '/projects/{id}', name: 'forecast_import_edit_project', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editProject(int $id, Request $request): Response
    {
        $data = $this->import->data();
        $row = $data === null ? null : $this->findRow($this->mappings->projectRows($data), $id);
        if ($row === null) {
            throw $this->createNotFoundException();
        }

        $mappingData = MappingData::fromTarget($row['target'], Target::WORK);
        if ($row['target'] === null && ($best = $row['suggestions'][0] ?? null) !== null) {
            $project = $this->catalogue->project($best['candidate']->projectId);
            $mappingData->customer = $project?->getCustomer();
            $mappingData->project = $project;
        }

        $form = $this->createForm(ProjectMappingForm::class, $mappingData, [
            'action' => $this->generateUrl('forecast_import_edit_project', ['id' => $id]),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->mappings->save(Mapping::PROJECT, $id, $form->getData()->toTarget());

            return $this->saved($request, 'forecast_import_projects');
        }

        return $this->render('@ForecastImport/edit.html.twig', [
            'form' => $form->createView(),
            'title' => $row['name'],
            'subtitle' => $row['client'],
            'assignments' => $row['assignments'],
            'notesRow' => $row,
            'back' => $this->generateUrl('forecast_import_projects'),
            'resetUrl' => $this->generateUrl('forecast_import_reset', ['kind' => Mapping::PROJECT, 'id' => $id]),
        ]);
    }

    #[Route(path: '/reset/{kind}/{id}', name: 'forecast_import_reset', requirements: ['kind' => 'person|placeholder|project', 'id' => '\d+'], methods: ['POST'])]
    public function reset(string $kind, int $id, Request $request): Response
    {
        if ($this->isCsrfTokenValid('forecast_import_reset', (string) $request->request->get('_token'))) {
            $this->mappings->forget($kind, $id);
            $this->flashSuccess('action.update.success');
        } else {
            $this->flashError('forecast_import.expired');
        }

        return $this->redirectToRoute($kind === Mapping::PROJECT ? 'forecast_import_projects' : 'forecast_import_people');
    }

    #[Route(path: '/tentative', name: 'forecast_import_tentative', methods: ['GET', 'POST'])]
    public function tentative(Request $request): Response
    {
        $data = $this->import->data();
        if ($data === null) {
            return $this->redirectToRoute('forecast_import');
        }

        // a tag is stored as a mapping key of at most 150 characters; Forecast tags that long are not offered
        $tags = array_values(array_filter(
            array_unique(array_merge($data->projectTags(), $this->mappings->tentativeTags())),
            static fn (string $tag): bool => mb_strlen($tag) <= 150
        ));
        $form = $this->createForm(TentativeTagsForm::class, ['tags' => $this->mappings->tentativeTags()], [
            'tags' => $tags,
            'action' => $this->generateUrl('forecast_import_tentative'),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->mappings->saveTentativeTags(array_values(array_map('strval', (array) $form->getData()['tags'])));
            $this->flashSuccess('action.update.success');

            return $this->redirectToRoute('forecast_import_preview');
        }

        return $this->render('@ForecastImport/tentative.html.twig', [
            'steps' => $this->steps($data, 'tentative'),
            'data' => $data,
            'form' => $form->createView(),
            'supported' => $this->import->capabilities()->supportsTentative(),
        ]);
    }

    #[Route(path: '/preview', name: 'forecast_import_preview', methods: ['GET'])]
    public function preview(Request $request): Response
    {
        $data = $this->import->data();
        if ($data === null) {
            return $this->redirectToRoute('forecast_import');
        }

        $plan = $this->import->plan($data);
        $result = $this->import->preview($data);
        $imported = $request->getSession()->remove(self::SESSION_IMPORTED);

        return $this->render('@ForecastImport/preview.html.twig', [
            'steps' => $this->steps($data, 'preview'),
            'data' => $data,
            'plan' => $plan,
            'result' => $result,
            'people' => Preview::byPerson($plan, $result),
            'imported' => \is_array($imported) ? $imported : null,
            'savedToken' => $this->vault->saved(),
            'blocking' => Plan::BLOCKING,
            'capabilities' => $this->import->capabilities(),
        ]);
    }

    #[Route(path: '/run', name: 'forecast_import_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        $data = $this->import->data();
        if ($data === null || !$this->isCsrfTokenValid('forecast_import_run', (string) $request->request->get('_token'))) {
            $this->flashError('forecast_import.expired');

            return $this->redirectToRoute('forecast_import_preview');
        }
        // the import must use the read the preview showed, not one someone made since
        if ($request->request->get('read') !== $data->fetchedAt->format('c')) {
            $this->flashWarning('forecast_import.read_since_preview');

            return $this->redirectToRoute('forecast_import_preview');
        }

        try {
            $result = $this->import->import($data);
            $request->getSession()->set(self::SESSION_IMPORTED, [
                'created' => $result->created,
                'updated' => $result->updated,
                'removed' => $result->removed,
                'unchanged' => $result->unchanged,
                'planningsCreated' => $result->planningsCreated,
                'planningsRemoved' => $result->planningsRemoved + $result->planningsShortened,
                'begin' => $data->begin->format('Y-m-d'),
            ]);
        } catch (\Exception $exception) {
            $this->flashUpdateException($exception);
        }

        return $this->redirectToRoute('forecast_import_preview');
    }

    #[Route(path: '/token/clear', name: 'forecast_import_clear_token', methods: ['POST'])]
    public function clearToken(Request $request): Response
    {
        if ($this->isCsrfTokenValid('forecast_import_token', (string) $request->request->get('_token'))) {
            $this->vault->clear();
            $this->flashSuccess('forecast_import.token_cleared');
        } else {
            $this->flashError('forecast_import.expired');
        }

        return $this->redirectToRoute($request->request->get('back') === 'preview' ? 'forecast_import_preview' : 'forecast_import');
    }

    #[Route(path: '/remove', name: 'forecast_import_remove', methods: ['POST'])]
    public function removeAll(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('forecast_import_remove', (string) $request->request->get('_token'))) {
            $this->flashError('forecast_import.expired');
        } elseif ($request->request->getBoolean('confirm')) {
            $count = $this->import->removeAll();
            $this->addFlash('success', $this->translator->trans('forecast_import.removed', ['%count%' => $count], 'flashmessages'));
        }

        return $this->redirectToRoute('forecast_import');
    }

    /**
     * After a mapping is saved: a dialog only needs to hear it worked (the list then refreshes the changed row), a full
     * page goes back to the list.
     */
    private function saved(Request $request, string $route): Response
    {
        if ($request->headers->get('X-Requested-With') === 'Kimai-Modal') {
            return new Response('');
        }
        $this->flashSuccess('action.update.success');

        return $this->redirectToRoute($route);
    }

    /**
     * The five steps with how far each one is, for the step bar on every page.
     *
     * @return list<array{key: string, route: string, title: string, status: ?string, done: bool, active: bool, enabled: bool}>
     */
    private function steps(?ForecastData $data, string $active): array
    {
        $people = $projects = null;
        if ($data !== null) {
            $people = $this->mappings->personRows($data);
            $projects = $this->mappings->projectRows($data, false);
        }
        $mapped = static fn (?array $rows): int => \count(array_filter($rows ?? [], static fn (array $row): bool => $row['target'] !== null));
        $tr = fn (string $key, array $parameters = []): string => $this->translator->trans($key, $parameters);

        $steps = [
            ['key' => 'read', 'route' => 'forecast_import', 'title' => $tr('forecast_import.step_connect'), 'status' => $data === null ? null : $tr('forecast_import.step_read_status', ['%count%' => \count($data->assignments)]), 'done' => $data !== null],
            ['key' => 'people', 'route' => 'forecast_import_people', 'title' => $tr('forecast_import.step_people'), 'status' => $people === null ? null : $tr('forecast_import.mapped_of', ['%done%' => $mapped($people), '%total%' => \count($people)]), 'done' => $people !== null && $mapped($people) === \count($people)],
            ['key' => 'projects', 'route' => 'forecast_import_projects', 'title' => $tr('forecast_import.step_projects'), 'status' => $projects === null ? null : $tr('forecast_import.mapped_of', ['%done%' => $mapped($projects), '%total%' => \count($projects)]), 'done' => $projects !== null && $mapped($projects) === \count($projects)],
            ['key' => 'tentative', 'route' => 'forecast_import_tentative', 'title' => $tr('forecast_import.step_tentative'), 'status' => $data === null ? null : $tr('forecast_import.tags_chosen', ['%count%' => \count($this->mappings->tentativeTags())]), 'done' => $data !== null],
            ['key' => 'preview', 'route' => 'forecast_import_preview', 'title' => $tr('forecast_import.step_preview'), 'status' => null, 'done' => false],
        ];

        return array_map(static fn (array $step): array => $step + ['active' => $step['key'] === $active, 'enabled' => $step['key'] === 'read' || $data !== null], $steps);
    }

    /**
     * @template T of array{id: int}
     * @param list<T> $rows
     * @return T|null
     */
    private function findRow(array $rows, int $id, ?string $kind = null): ?array
    {
        foreach ($rows as $row) {
            if ($row['id'] === $id && ($kind === null || ($row['kind'] ?? null) === $kind)) {
                return $row;
            }
        }

        return null;
    }
}
