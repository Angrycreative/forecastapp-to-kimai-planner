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
use KimaiPlugin\ForecastImportBundle\Import\Plan;
use KimaiPlugin\ForecastImportBundle\Import\ProjectSuggester;
use KimaiPlugin\ForecastImportBundle\Import\Target;
use KimaiPlugin\ForecastImportBundle\Repository\ImportedPlanningRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The import screens: connect and fetch, map people, map projects, choose tentative tags, preview and import.
 */
#[Route(path: '/admin/forecast-import')]
#[IsGranted('forecast_import')]
final class ForecastImportController extends AbstractController
{
    public function __construct(
        private readonly ForecastImport $import,
        private readonly MappingService $mappings,
        private readonly ImportedPlanningRepository $links,
        private readonly KimaiCatalogue $catalogue,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'forecast_import', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $data = $this->import->data();
        $form = $this->createForm(ConnectForm::class, [
            'accountId' => $data?->accountId,
            'begin' => new \DateTime('today'),
            'end' => new \DateTime('today +12 months'),
        ], ['action' => $this->generateUrl('forecast_import')]);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $values = $form->getData();
            try {
                $this->import->fetch(
                    new ForecastCredentials((string) $values['accountId'], (string) $values['token']),
                    \DateTimeImmutable::createFromMutable($values['begin'])->setTime(0, 0),
                    \DateTimeImmutable::createFromMutable($values['end'])->setTime(0, 0),
                );
                $this->flashSuccess('forecast_import.fetched');

                return $this->redirectToRoute('forecast_import');
            } catch (ForecastApiException $exception) {
                $this->flashError('forecast_import.fetch_failed', $exception->getMessage());
            }
        }

        $summary = null;
        if ($data !== null) {
            $people = $this->mappings->personRows($data);
            $projects = $this->mappings->projectRows($data, false);
            $summary = [
                'people' => \count($people),
                'people_mapped' => \count(array_filter($people, static fn (array $row): bool => $row['target'] !== null)),
                'projects' => \count($projects),
                'projects_mapped' => \count(array_filter($projects, static fn (array $row): bool => $row['target'] !== null)),
                'tags' => \count($data->projectTags()),
                'tentative_tags' => \count($this->mappings->tentativeTags()),
            ];
        }

        return $this->render('@ForecastImport/index.html.twig', [
            'form' => $form->createView(),
            'data' => $data,
            'summary' => $summary,
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
            $this->flashSuccess('action.update.success');

            return $this->redirectToRoute('forecast_import_people');
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

    #[Route(path: '/projects', name: 'forecast_import_projects', methods: ['GET'])]
    public function projects(): Response
    {
        $data = $this->import->data();
        if ($data === null) {
            return $this->redirectToRoute('forecast_import');
        }

        return $this->render('@ForecastImport/projects.html.twig', [
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
            $this->flashSuccess('action.update.success');

            return $this->redirectToRoute('forecast_import_projects');
        }

        return $this->render('@ForecastImport/edit.html.twig', [
            'form' => $form->createView(),
            'title' => $row['name'],
            'subtitle' => $row['client'],
            'assignments' => $row['assignments'],
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

        $tags = array_values(array_unique(array_merge($data->projectTags(), $this->mappings->tentativeTags())));
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
            'form' => $form->createView(),
            'supported' => $this->import->capabilities()->supportsTentative(),
        ]);
    }

    #[Route(path: '/preview', name: 'forecast_import_preview', methods: ['GET'])]
    public function preview(): Response
    {
        $data = $this->import->data();
        if ($data === null) {
            return $this->redirectToRoute('forecast_import');
        }

        $plan = $this->import->plan($data);

        return $this->render('@ForecastImport/preview.html.twig', [
            'data' => $data,
            'plan' => $plan,
            'result' => $this->import->preview($data),
            'blocking' => Plan::BLOCKING,
            'capabilities' => $this->import->capabilities(),
        ]);
    }

    #[Route(path: '/run', name: 'forecast_import_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        $data = $this->import->data();
        if ($data === null || !$this->isCsrfTokenValid('forecast_import_run', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('forecast_import_preview');
        }

        try {
            $result = $this->import->import($data);
            $this->addFlash('success', $this->translator->trans('forecast_import.imported', [
                '%created%' => $result->created,
                '%updated%' => $result->updated,
                '%removed%' => $result->removed,
                '%unchanged%' => $result->unchanged,
            ], 'flashmessages'));
        } catch (\Exception $exception) {
            $this->flashUpdateException($exception);
        }

        return $this->redirectToRoute('forecast_import_preview');
    }

    #[Route(path: '/remove', name: 'forecast_import_remove', methods: ['POST'])]
    public function removeAll(Request $request): Response
    {
        if ($this->isCsrfTokenValid('forecast_import_remove', (string) $request->request->get('_token')) && $request->request->getBoolean('confirm')) {
            $count = $this->import->removeAll();
            $this->addFlash('success', $this->translator->trans('forecast_import.removed', ['%count%' => $count], 'flashmessages'));
        }

        return $this->redirectToRoute('forecast_import');
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
