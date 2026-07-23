<?php



declare(strict_types=1);



namespace SimpleKuma\Api\Controllers;



use mysqli;

use SimpleKuma\Api\ApiAuthContext;

use SimpleKuma\Api\Request;

use SimpleKuma\Api\Response;

use SimpleKuma\Auth\Permission;

use SimpleKuma\Stats\CampaignStatsBreakdownService;

use SimpleKuma\Stats\CampaignStatsService;

use SimpleKuma\Stats\ClicksQueryService;

use SimpleKuma\Stats\ConversionsQueryService;

use SimpleKuma\Utils\Formatter;



class StatsController extends BaseController

{

    public static function campaigns(mysqli $db, Request $request): void

    {

        $ctx = self::auth($db, $request);

        self::requirePerm($ctx, Permission::PERM_STATS_VIEW);



        $params = self::statsDateParams($ctx, $request);

        $groupBy = trim((string)($request->query('group_by') ?? ''));



        if ($groupBy !== '') {

            $campaignId = $request->queryInt('campaign_id');

            if ($campaignId === null || $campaignId < 1) {

                Response::validationError(

                    'campaign_id is required when group_by is set on the all-campaigns stats endpoint.',

                    ['campaign_id' => 'Required when group_by is present.']

                );

            }

            self::respondGroupedStats($db, $campaignId, $groupBy, $params, $request);

        }



        $service = new CampaignStatsService($db);

        Response::success($service->getCampaignStats(null, $params['from'], $params['to'], $params['timezone']));

    }



    public static function campaign(mysqli $db, Request $request): void

    {

        $ctx = self::auth($db, $request);

        self::requirePerm($ctx, Permission::PERM_STATS_VIEW);



        $id = self::idParam($request);

        $params = self::statsDateParams($ctx, $request);

        $groupBy = trim((string)($request->query('group_by') ?? ''));



        if ($groupBy !== '') {

            self::respondGroupedStats($db, $id, $groupBy, $params, $request);

        }



        $service = new CampaignStatsService($db);

        $rows = $service->getCampaignStats($id, $params['from'], $params['to'], $params['timezone']);

        if ($rows === []) {

            Response::error('Campaign not found or no data', 404, 'not_found');

        }



        Response::success($rows[0]);

    }



    public static function clicks(mysqli $db, Request $request): void

    {

        $ctx = self::auth($db, $request);

        self::requirePerm($ctx, Permission::PERM_VISITOR_LOG_VIEW);



        $timezone = $ctx->user['timezone'] ?? 'UTC';

        $timezone = $request->query('timezone') ?? $timezone;

        $today = Formatter::getTodayInTimezone($timezone);

        $dateFrom = $request->query('from') ?? $today;

        $dateTo = $request->query('to') ?? $today;

        $campaignId = $request->queryInt('campaign_id');

        $page = max(1, $request->queryInt('page') ?? 1);

        $perPage = $request->queryInt('per_page') ?? 50;



        $service = new ClicksQueryService($db);

        $result = $service->listClicks($campaignId, $dateFrom, $dateTo, $timezone, $page, $perPage ?? 50);

        Response::list($result['rows'], $result['total'], $page, $perPage ?? 50);

    }



    public static function conversions(mysqli $db, Request $request): void

    {

        $ctx = self::auth($db, $request);

        self::requirePerm($ctx, Permission::PERM_STATS_VIEW);



        $timezone = $ctx->user['timezone'] ?? 'UTC';

        $timezone = $request->query('timezone') ?? $timezone;

        $today = Formatter::getTodayInTimezone($timezone);

        $dateFrom = $request->query('from') ?? $today;

        $dateTo = $request->query('to') ?? $today;

        $campaignId = $request->queryInt('campaign_id');

        $page = max(1, $request->queryInt('page') ?? 1);

        $perPage = $request->queryInt('per_page') ?? 50;



        $service = new ConversionsQueryService($db);

        $result = $service->listConversions($campaignId, $dateFrom, $dateTo, $timezone, $page, $perPage ?? 50);

        Response::list($result['rows'], $result['total'], $page, $perPage ?? 50);

    }



    /**

     * @return array{from: string, to: string, timezone: string}

     */

    private static function statsDateParams(ApiAuthContext $ctx, Request $request): array

    {

        $timezone = $ctx->user['timezone'] ?? 'UTC';

        $timezone = $request->query('timezone') ?? $timezone;

        $today = Formatter::getTodayInTimezone($timezone);



        return [

            'from' => $request->query('from') ?? $today,

            'to' => $request->query('to') ?? $today,

            'timezone' => $timezone,

        ];

    }



  /**

     * @param array{from: string, to: string, timezone: string} $params

     */

    private static function respondGroupedStats(

        mysqli $db,

        int $campaignId,

        string $groupBy,

        array $params,

        Request $request

    ): void {

        $page = max(1, $request->queryInt('page') ?? 1);

        $perPage = max(1, min(1000, $request->queryInt('per_page') ?? 50));

        $sort = $request->query('sort') ?? 'clicks';

        $order = $request->query('order') ?? 'desc';



        $service = new CampaignStatsBreakdownService($db);



        try {

            $result = $service->getGroupedStats(

                $campaignId,

                $groupBy,

                $params['from'],

                $params['to'],

                $params['timezone'],

                $page,

                $perPage,

                is_string($sort) ? $sort : 'clicks',

                is_string($order) ? $order : 'desc'

            );

        } catch (\InvalidArgumentException $e) {

            Response::validationError($e->getMessage(), ['group_by' => $e->getMessage()]);

        }



        if ($result['rows'] === [] && $result['total'] === 0) {

            $summary = (new CampaignStatsService($db))->getCampaignStats(

                $campaignId,

                $params['from'],

                $params['to'],

                $params['timezone']

            );

            if ($summary === []) {

                Response::error('Campaign not found or no data', 404, 'not_found');

            }

        }



        Response::success($result['rows'], 200, [

            'page' => $page,

            'per_page' => $perPage,

            'total' => $result['total'],

            'group_by' => $groupBy,

            'campaign_id' => $campaignId,

            'date_from' => $params['from'],

            'date_to' => $params['to'],

            'timezone' => $params['timezone'],

            'totals' => $result['totals'],

        ]);

    }

}

