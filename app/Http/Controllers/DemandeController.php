<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Compte;
use App\Enums\RoleEnum;
use App\Models\Demande;
use App\Mail\DemandeMail;
use App\Models\Livraison;
use App\Models\Delegation;
use App\Models\Traitement;
use App\Models\Approbateur;
use App\Mail\DeliveriesMail;
use Illuminate\Http\Request;
use App\Models\DemandeDetail;
use App\Models\Mail as MailModel;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Direction;

class DemandeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $connected_user = Session::get('authUser');

        if ($this->isManager($connected_user)) {
            $connected_user['manager'] = true;
        }

        if ($this->isApprover($connected_user)) {
            $connected_user['approver'] = true;
        }

        if ($this->isDelegated($connected_user)) {
            $connected_user['delegated'] = true;
        }

        if ($connected_user->compte->role->value === 'livraison') {
            $connected_user['deliver'] = true;
        }
        $ongoings = $this->getOngoingReqs($connected_user);
        $collaborators = $this->getCollaboratorsReqs($connected_user);
        $delegations = $this->getDelegationsReqs($connected_user);
        $validate = $this->getReqsToValidate($connected_user);
        $historics = $this->getReqsHistoric($connected_user);
        $statistics = $this->getStatistics($connected_user);
        return view('demandes.index', compact('ongoings', 'connected_user', 'historics', 'collaborators', 'delegations', 'validate', 'statistics'));
    }

    private function isManager($user)
    {
        if (User::whereHas('compte', function (Builder $query) use ($user) {
            $query->where('manager', $user->id)->where('user_id', '!=', $user->id);
        })->exists()) {
            return true;
        } else {
            return false;
        }
    }

    private function isApprover($user)
    {
        if (Approbateur::where('email', $user->email)->exists()) {
            return true;
        } else {
            return false;
        }
    }

    private function isDelegated($user)
    {
        $isDelegated = User::whereHas('delegations', function (Builder $query) use ($user) {
            $query->where('user_id', $user->id)->where('date_debut', '<=', Carbon::today())->where('date_fin', '>=', Carbon::today());
        })->exists();

        if ($isDelegated) {
            return true;
        } else {
            return false;
        }
    }

    private function getOngoingReqs($user)
    {
        if ($user->compte->role->value === 'user') {
            $reqs = Demande::with('demande_details')->whereHas('traitement', function ($query) use ($user) {
                $query->where('demandeur_id', $user->id)->where('status', 'en cours');
            })
                ->orderBy('created_at', 'desc')
                ->paginate(12);
            foreach ($reqs as $req) {
                $last_flow = Traitement::where('demande_id', $req->id)->get()->last();
                $req['level'] = $last_flow->level;
                if ($last_flow && $last_flow->approbateur_id === $user->id) {
                    if ($req->user_id === $user->id) {
                        $req['validator'] = true;
                    } else {
                        $req['validator'] = false;
                    }
                }
            }
        }

        if ($user->compte->role->value === 'livraison') {
            $demandes = Demande::all();
            $all_validated_keys = [];
            foreach ($demandes as $key => $req) {
                $last = Traitement::where('demande_id', $req->id)->orderBy('id', 'DESC')->first();
                if ($last && $last->status === 'validé') {
                    $all_validated_keys[$key] = $req->id;
                }
            }

            $validated_reqs = Demande::whereIn('id', $all_validated_keys)->get();
            $on_going = [];
            foreach ($validated_reqs as $key => $validated) {
                $req_details = DemandeDetail::where('demande_id', $validated->id)->get();
                $delivered = 0;
                foreach ($req_details as $req_detail) {
                    $req_count = $req_detail->qte_demandee;
                    $count = 0;
                    if (Livraison::where('demande_detail_id', $req_detail->id)->exists()) {
                        $deliveries = Livraison::where('demande_detail_id', $req_detail->id)->get();
                        foreach ($deliveries as $key => $delivery) {
                            $count += $delivery->quantite;
                        }
                        if ($req_count === $count) {
                            $delivered += 1;
                        }
                    }
                }
                if ($delivered < $req_details->count()) {
                    $on_going[] = $validated;
                }
            }
            $demandes_array = collect($on_going);
            $reqs = Demande::with('demande_details')->whereIn('id', $demandes_array->pluck('id'))->orderBy('id', 'desc')->paginate(12);
        }
        return $reqs;
    }

    private function getCollaboratorsReqs($user)
    {
        $isManager = Compte::where('manager', $user->id)->exists();
        if ($isManager) {
            $userCollaborators = User::whereHas('compte', function (Builder $query) use ($user) {
                $query->where('manager', $user->id)->where('user_id', '!=', $user->id);
            })->get();
            $collabs_req_keys = [];
            foreach ($userCollaborators as $collaborator) {
                $reqs_collabs = Demande::with('demande_details')->where('user_id', $collaborator->id)->latest()->get();
                foreach ($reqs_collabs as $req) {
                    $collabs_req_keys[] = $req->id;
                }
            }
            $demandes = Demande::with('demande_details')->whereIn('id', $collabs_req_keys)->latest()->paginate(9);
            foreach ($demandes as $demande) {
                $last_flow = Traitement::where('demande_id', $demande->id)->get()->last();
                if ($last_flow) {
                    $demande['level'] = $last_flow->level;
                    if ($last_flow->approbateur_id === $user->id) {
                        $demande['validator'] = true;
                    } else {
                        $demande['validator'] = false;
                    }
                    if ($last_flow->status === 'rejeté') {
                        $demande['status'] = 'Rejeté';
                    } elseif ($last_flow->status === 'validé') {
                        $count = 0;
                        $details = $demande->demande_details()->get();
                        foreach ($details as $key => $detail) {
                            if ($detail->qte_demandee === $detail->qte_livree) {
                                $count += 1;
                            }
                        }
                        if ($count === $details->count()) {
                            $demande['status'] = 'Livré';
                        } else {
                            $demande['status'] = 'En cours de livraison';
                        }
                    } else {
                        $demande['status'] = 'En cours';
                    }
                }
            }
        } else {
            $demandes = [];
        }
        return $demandes;
    }

    private function getDelegationsReqs($user)
    {
        $delegation = Delegation::where('user_id', $user->id)->where('date_debut', '<=', Carbon::today())->where('date_fin', '>=', Carbon::today())->first();
        if ($delegation) {
            $manager = User::find($delegation->manager);
            if ($this->isManager($manager) || $this->isApprover($manager)) {
                $demandes = Demande::with('demande_details')->whereHas('traitement', function (Builder $query) use ($manager) {
                    $query->where('approbateur_id', $manager->id)->where('status', 'en cours');
                })->latest()->paginate(12);

                foreach ($demandes as $demande) {
                    $last_flow = Traitement::where('demande_id', $demande->id)->where('approbateur_id', $manager->id)->get()->last();
                    $demande['level'] = $last_flow->level;
                    if ($last_flow->approbateur_id === $manager->id) {
                        $demande['validator'] = true;
                    } else {
                        $demande['validator'] = false;
                    }
                }
            }

            if ($manager->compte->role->value === 'livraison') {
                $user['deliver'] = true;
                $reqs = Demande::all();
                $all_validated_keys = [];
                foreach ($reqs as $key => $req) {
                    $last = Traitement::where('demande_id', $req->id)->orderBy('id', 'DESC')->first();
                    if ($last && $last->status === 'validé') {
                        $all_validated_keys[$key] = $req->id;
                    }
                }

                $validated_reqs = Demande::whereIn('id', $all_validated_keys)->get();
                $on_going = [];
                foreach ($validated_reqs as $key => $validated) {
                    $req_details = DemandeDetail::where('demande_id', $validated->id)->get();
                    $delivered = 0;
                    foreach ($req_details as $req_detail) {
                        $req_count = $req_detail->qte_demandee;
                        $count = 0;
                        if (Livraison::where('demande_detail_id', $req_detail->id)->exists()) {
                            $deliveries = Livraison::where('demande_detail_id', $req_detail->id)->get();
                            foreach ($deliveries as $key => $delivery) {
                                $count += $delivery->quantite;
                            }
                            if ($req_count === $count) {
                                $delivered += 1;
                            }
                        }
                    }
                    if ($delivered < $req_details->count()) {
                        $on_going[] = $validated;
                    }
                }
                $demandes_array = collect($on_going);
                $demandes = Demande::with('demande_details')->whereIn('id', $demandes_array->pluck('id'))->orderBy('id', 'desc')->paginate(12);
            }
        } else {
            $demandes = [];
        }
        return $demandes;
    }

    private function getReqsToValidate($user)
    {
        if ($user->approver) {
            $demandes = Demande::with('demande_details')->whereHas('traitement', function (Builder $query) use ($user) {
                $query->where('approbateur_id', $user->id)->where('status', 'en cours');
            })->latest()->paginate(12);

            foreach ($demandes as $demande) {
                $last_flow = Traitement::where('demande_id', $demande->id)->where('approbateur_id', $user->id)->get()->last();
                $demande['level'] = $last_flow->level;
                if ($last_flow->approbateur_id === $user->id) {
                    $demande['validator'] = true;
                } else {
                    $demande['validator'] = false;
                }
            }
        } else {
            $demandes = [];
        }
        // dd($demandes);
        return $demandes;
    }

    private function getStatistics($user)
    {
        /*
        //$statistics = 'Onglet des Statistiques biscuits';
        $statistics = [];

        // Nombre total de demandes
        $statistics['total_requests'] = Demande::count();

        // Nombre de demandes par direction
        $statistics['requests_by_direction'] = Demande::select('service', DB::raw('count(*) as total'))
            ->groupBy('service')
            ->get();

        // Nombre de demandes livrées
        $statistics['delivered_requests'] = Demande::whereHas('traitement', function ($query) {
            $query->where('status', 'livré');
        })->count();

        //Nombre des demandes du mois passé
        $statistics['last_monthly_requests'] = Demande::whereMonth('created_at', Carbon::now()->subMonth()->month)->count();

        //Nombre des demandes du mois actuel

        $statistics ['this_month_requests'] = Demande::whereMonth('created_at', Carbon::now()->month)->count();

        /*$statistics['direction_requests'] = Demande::selectRaw('service, COUNT(*) as count')
        ->groupBy('service')
        ->orderBy('count', 'desc')
        ->count();


        // Nombre de demandes validées
        $statistics['validated_requests'] = Demande::whereHas('traitement', function ($query) {
            $query->where('status', 'validé');
        })->count();

        // Nombre de demandes rejetées
        $statistics['rejected_requests'] = Demande::whereHas('traitement', function ($query) {
            $query->where('status', 'rejeté');
        })->count();

        // Nombre de demandes par mois pour l'année en cours





        return $statistics;
        */
        $demandes = Demande::all();
        $stats = [];
        $all_validated_keys = [];
        foreach ($demandes as $key => $demande) {
            $last = Traitement::where('demande_id', $demande->id)->orderBy('id', 'DESC')->first();
            if ($last && $last->status === 'validé') {
                $all_validated_keys[$key] = $demande->id;
            }
        }
        $validated_reqs = Demande::whereIn('id', $all_validated_keys)->get();
        $stats['all_reqs'] = $validated_reqs->count();
        foreach ($validated_reqs as $key => $validated) {
            $req_details = DemandeDetail::where('demande_id', $validated->id)->get();
            $delivered = 0;
            foreach ($req_details as $req_detail) {
                $req_count = $req_detail->qte_demandee;
                $deliveries = Livraison::where('demande_detail_id', $req_detail->id)->get();
                $count = 0;
                if ($deliveries->count() > 0) {
                    foreach ($deliveries as $key => $delivery) {
                        $count += $delivery->quantite;
                    }
                }
                if ($req_count === $count) {
                    $delivered += 1;
                }
            }
            if ($delivered === $req_details->count()) {
                $reqs_delivered[] = $validated;
            }
        }
        $demandes_array = collect($reqs_delivered);
        $stats['delivered'] = Demande::whereIn('id', $demandes_array->pluck('id'))->orderBy('created_at', 'desc')->count();
        $validated_keys = [];
        foreach ($validated_reqs as $key => $validated) {
            $validated_keys[$key] = $validated->id;
        }
        $req_month_count = Demande::whereIn('id', $validated_keys)->whereMonth('created_at', Carbon::now()->month)->count();
        $stats['month_count'] = $req_month_count;
        Carbon::setlocale('fr');
        $months = [];
        for ($month = 0; $month <= 11; $month++) {
            $months[$month]['name'] = Carbon::create()->month($month + 1)->translatedFormat('F');
            $months[$month]['count'] = Demande::whereIn('id', $validated_keys)->whereMonth('created_at', $month + 1)->whereYear('created_at', Carbon::now()->year)->count();
        }
        $on_going = [];
        foreach ($validated_reqs as $key => $validated) {
            $req_details = DemandeDetail::where('demande_id', $validated->id)->get();
            $delivered = 0;
            foreach ($req_details as $req_detail) {
                $req_count = $req_detail->qte_demandee;
                $count = 0;
                if (Livraison::where('demande_detail_id', $req_detail->id)->exists()) {
                    $deliveries = Livraison::where('demande_detail_id', $req_detail->id)->get();
                    foreach ($deliveries as $key => $delivery) {
                        $count += $delivery->quantite;
                    }
                    if ($req_count === $count) {
                        $delivered += 1;
                    }
                }
            }
            if ($delivered < $req_details->count()) {
                $on_going[] = $validated;
            }
        }
        $reqs_array = collect($on_going);
        $ongoing_reqs = Demande::whereIn('id', $reqs_array->pluck('id'))->latest()->paginate(5);
        $reqs_count = Demande::whereIn('id', $reqs_array->pluck('id'))->count();
        $stats['ongoing_reqs'] = $ongoing_reqs;
        $stats['ongoing'] = $reqs_count;
        $directions = Direction::withTrashed()->get();
        $array_direction_req_count = [];
        foreach ($directions as $key => $direction) {
            $userD = User::whereHas('compte', function (Builder $query) use ($direction) {
                $query->where('direction_id', $direction->id);
            })->get();
            if ($userD) {
                $users_count = 0;
                foreach ($userD as $user) {
                    $user_reqs = Demande::where('user_id', $user->id)->get();
                    $user_req_validated = [];
                    $user_count = 0;
                    if ($user_reqs !== []) {
                        foreach ($user_reqs as $key => $user_req) {
                            $last_tr = Traitement::where('demande_id', $user_req->id)->orderBy('id', 'desc')->first();
                            if ($last_tr !== null) {
                                if ($last_tr->status === 'validé') {
                                    $user_req_validated[$key] = $user_req;
                                }
                            }
                            $user_count = count($user_req_validated);
                        }
                    }
                    $users_count += $user_count;
                }
            }
            $direction['req_count'] = $users_count;
            $array_direction_req_count[$key] = $users_count;
        }
        $best_direction = $directions->where('req_count', max($array_direction_req_count))->first();
        $stats['directions'] = $directions;
        $stats['best_direction'] = $best_direction;
        $stats['months'] = $months;
        return $stats;
    }

    private function getReqsHistoric($user)
    {
        if ($user->compte->role->value === 'user') {
            $demandes = Demande::with('demande_details')->whereHas('traitement', function (Builder $query) use ($user) {
                $query->where('approbateur_id', $user->id)
                    ->orWhere('demandeur_id', $user->id)
                    ->where('status', '!=', 'en cours');
            })
                ->orderBy('created_at', 'desc')
                ->paginate(9);
        }
        if ($user->compte->role->value === 'livraison') {
            $reqs = Demande::all();
            $all_validated_keys = [];
            foreach ($reqs as $key => $req) {
                $last = Traitement::where('demande_id', $req->id)->orderBy('id', 'DESC')->first();
                if ($last && $last->status === 'validé') {
                    $all_validated_keys[$key] = $req->id;
                }
            }
            $validated_reqs = Demande::whereIn('id', $all_validated_keys)->get();
            $reqs_delivered = [];
            foreach ($validated_reqs as $key => $validated) {
                $req_details = DemandeDetail::where('demande_id', $validated->id)->get();
                $delivered = 0;
                foreach ($req_details as $req_detail) {
                    $req_count = $req_detail->qte_demandee;
                    $deliveries = Livraison::where('demande_detail_id', $req_detail->id)->get();
                    $count = 0;
                    if ($deliveries->count() > 0) {
                        foreach ($deliveries as $key => $delivery) {
                            $count += $delivery->quantite;
                        }
                    }
                    if ($req_count === $count) {
                        $delivered += 1;
                    }
                }
                if ($delivered === $req_details->count()) {
                    $reqs_delivered[] = $validated;
                }
            }
            $demandes_array = collect($reqs_delivered);
            $demandes = Demande::with('demande_details')->whereIn('id', $demandes_array->pluck('id'))->orderBy('created_at', 'desc')->paginate(9);
        }

        foreach ($demandes as $key => $req) {
            $last_flow = Traitement::where('demande_id', $req->id)->orderBy('id', 'DESC')->first();
            if ($last_flow->status === 'validé') {
                $details = $req->demande_details()->get();
                $count = 0;
                foreach ($details as $key => $detail) {
                    if ($detail->qte_demandee === $detail->qte_livree) {
                        $count += 1;
                    }
                }
                if ($count === $details->count()) {
                    $req['status'] = 'Livré';
                } else {
                    $req['status'] = 'En attente de livraison';
                }
            } elseif ($last_flow->status === 'rejeté') {
                $req['status'] = 'Rejeté';
            } else {
                $req['status'] = 'En cours';
            }
        }
        return $demandes;
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('demandes.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $order = Demande::count() === 0 ? 1 : Demande::get()->last()->id + 1;
        $ref = "REQ-{$order}-" . Carbon::now()->year;
        $demande = Demande::create([
            'numero' => $ref,
            'service' => Session::get('authUser')->compte->service,
            'user_id' => Session::get('authUser')->id
        ]);
        if ($demande) {
            foreach ($request->demandes as $item) {
                DemandeDetail::create([
                    'designation' => $item["designation"],
                    'qte_demandee' => $item["qte_demandee"],
                    'qte_livree' => 0,
                    'demande_id' => $demande->id
                ]);
            }

            $traitement1 = Traitement::create([
                'demande_id' => $demande->id,
                'approbateur_id' => $demande->user->id,
                'demandeur_id' => $demande->user->id,
                'status' => 'validé',
            ]);

            if ($traitement1) {

                $traitement2 = Traitement::create([
                    'demande_id' => $demande->id,
                    'approbateur_id' => $demande->user->compte->manager,
                    'demandeur_id' => $demande->user->id,
                ]);
                if ($traitement2) {
                    MailModel::create([
                        'traitement_id' => $traitement2->id,
                    ]);

                    $demande['success'] = true;

                    Mail::to($demande->user->email, $demande->user->name)->send(new DemandeMail($demande));
                }
            }

            return redirect()->route('demandes.index')->with('success', 'Demande enregistrée avec succès');
        }

        return redirect()->route('demandes.index')->with('success', 'Demande enregistrée avec succès');
    }

    /**
     * Display the specified resource.
     */
    public function show(Demande $demande)
    {
        $en_cours = Traitement::where('demande_id', $demande->id)
            ->orderBy('id', 'DESC')
            ->first();
        $manager = User::find($demande->user->compte->manager);
        $manager_validator = Traitement::where('demande_id', $demande->id)->where('level', 0)->orderBy('id', 'desc')->first();
        // dd($demande, $manager, $manager_validator);
        if ($manager_validator->approbateur_id === $manager->id) {
            $demande['manager'] = $manager;
        } else {
            $demande['manager'] = User::find($manager_validator->approbateur_id);
        }
        $connected_user = Session::get('authUser');
        if ($connected_user->id === $en_cours->demandeur_id || $connected_user->id === $en_cours->approbateur_id) {
            $approbateurs = Approbateur::orderBy('level', 'ASC')->get()->keyBy('email');
            // dd($approbateurs);
            $traitements = Traitement::where('demande_id', $demande->id)
                ->orderBy('level', 'ASC')
                ->get();
            $users = User::all();

            $users_approbateurs = [];
            $validated_levels = [];
            foreach ($traitements as $traitement) {
                if (in_array($traitement->status, ['validé', 'rejeté'])) {
                    $validated_levels[] = $traitement->level;
                }
            }
            // les autres approbateurs
            foreach ($users as $user) {
                if (isset($approbateurs[$user->email])) {
                    $approbateur = $approbateurs[$user->email];
                    $a_valide = in_array($approbateur->level, $validated_levels);
                    // Ajouter seulement si les niveaux inférieurs sont validés
                    if (empty($validated_levels) || max($validated_levels) >= ($approbateur->level - 1)) {
                        $users_approbateurs[] = [
                            'user' => $user,
                            'level' => $approbateur->level,
                            'validated' => $a_valide,
                        ];
                    }
                }
            }
            // Trier les approbateurs
            $users_approbateurs = collect($users_approbateurs)->sortBy(function ($item) {
                return $item['validated'] ? -1 : $item['level'];
            });
            // informations finales pour la vue
            $final_approbateurs = $users_approbateurs->pluck('user');
            $date_validate = [];
            foreach ($traitements as $traitement) {
                $date_validate[] = $traitement->updated_at->format('d-m-Y H:i:s');
            }
            $demande['approbateurs'] = $final_approbateurs;
            return view('demandes.show', compact('demande', 'traitements', 'en_cours', 'date_validate'));
        } elseif ($connected_user->compte->role->value === RoleEnum::LIVRAISON->value) {
            if ($en_cours->status === 'validé') {


                $details = $demande->demande_details()->get();
                $count = 0;
                foreach ($details as $key => $detail) {
                    if ($detail->qte_demandee === $detail->qte_livree) {
                        $count += 1;
                    }
                }
                if ($count === $details->count()) {
                    $demande['delivered'] = true;
                }
                $traitements = Traitement::where('demande_id', $demande->id)
                    ->orderBy('level', 'ASC')
                    ->get();
                $approvers = [];
                foreach ($traitements as $traitement) {
                    if ($traitement->level !== 0) {
                        $user = User::find($traitement->approbateur_id);
                        $approvers[] = $user;
                    }
                }
                $demande['approbateurs'] = collect($approvers);

                $date_validate = [];
                foreach ($traitements as $traitement) {
                    $date_validate[] = $traitement->updated_at->format('d-m-Y H:i:s');
                }
                return view('demandes.show', compact('demande', 'traitements', 'en_cours', 'date_validate'));
            } else {
                return response()->view('errors.error', ['status_code' => 403], 403);
            }
        } else {
            return response()->view('errors.error', ['status_code' => 403], 403);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Demande $demande)
    {
        //

        return view('demandes.index', compact('demandes'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Demande $demande)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Demande $demande)
    {
        $demande->delete();
        return redirect()->back()->with('success', 'Suppression éffectuée avec succès');
    }

    public function historique()
    {
        $connected_user = Session::get('authUser');
        if ($connected_user->compte->role->value === 'user') {
            $demandes = Demande::whereHas('traitement', function (Builder $query) use ($connected_user) {
                $query->where('approbateur_id', $connected_user->id)
                    ->orWhere('demandeur_id', $connected_user->id)
                    ->where('status', '!=', 'en cours');
            })
                ->orderBy('created_at', 'desc')
                ->paginate(15);
        }
        if ($connected_user->compte->role->value === 'livraison') {
            $reqs = Demande::all();
            $all_validated_keys = [];
            foreach ($reqs as $key => $req) {
                $last = Traitement::where('demande_id', $req->id)->orderBy('id', 'DESC')->first();
                if ($last->status === 'validé') {
                    $all_validated_keys[$key] = $req->id;
                }
            }
            $validated_reqs = Demande::whereIn('id', $all_validated_keys)->get();
            $reqs_delivered = [];
            foreach ($validated_reqs as $key => $validated) {
                $req_details = DemandeDetail::where('demande_id', $validated->id)->get();
                $delivered = 0;
                foreach ($req_details as $req_detail) {
                    $req_count = $req_detail->qte_demandee;
                    $deliveries = Livraison::where('demande_detail_id', $req_detail->id)->get();
                    $count = 0;
                    if ($deliveries->count() > 0) {
                        foreach ($deliveries as $key => $delivery) {
                            $count += $delivery->quantite;
                        }
                    }
                    if ($req_count === $count) {
                        $delivered += 1;
                    }
                }
                if ($delivered === $req_details->count()) {
                    $reqs_delivered[] = $validated;
                }
            }
            $demandes_array = collect($reqs_delivered);
            $demandes = Demande::whereIn('id', $demandes_array->pluck('id'))->orderBy('created_at', 'desc')->paginate(10);
        }

        $requests = $demandes;
        foreach ($requests as $key => $req) {
            $last_flow = Traitement::where('demande_id', $req->id)->orderBy('id', 'DESC')->first();
            if ($last_flow->status === 'rejeté') {
                $req['status'] = 'Rejected';
            } elseif ($last_flow->status === 'validé') {
                $details = $req->demande_details()->get();
                $count = 0;
                foreach ($details as $key => $detail) {
                    if ($detail->qte_demandee === $detail->qte_livree) {
                        $count += 1;
                    }
                }
                if ($count === $details->count()) {
                    $req['status'] = 'Delivered';
                } else {
                    $req['status'] = 'In progress';
                }
            } else {
                $req['status'] = 'In progress';
            }
        }
        return view('demandes.historique', compact('demandes'));
    }

    public function updateLivraison(Request $request)
    {

        $validatedData = $request->validate([
            'details' => 'required|array',
        ]);

        foreach ($validatedData['details'] as $detail) {
            if ($detail['quantite'] !== null) {
                $demandeDetail = DemandeDetail::find((int)$detail['id']);
                $new_quantity = $demandeDetail->qte_livree + $detail['quantite'];
                if ($new_quantity > $demandeDetail->qte_demandee) {
                    return redirect()->back()->with('error', 'La quantité livrée ne peut pas être supérieure à la quantité demandée.');
                } else {
                    $delivery = Livraison::create([
                        'demande_detail_id' => $detail['id'],
                        'quantite' => $detail['quantite']
                    ]);

                    if ($delivery) {
                        $demandeDetail->update([
                            'qte_livree' => $new_quantity
                        ]);
                    }
                }
            }

            // $req = Demande::find((int)$request->req);
            // $req_details = DemandeDetail::where('demande_id', $req->id)->get();
            // $delivered = 0;
            // foreach ($req_details as $req_detail) {
            //     $req_count = $req_detail->qte_demandee;
            //     $deliveries = Livraison::where('demande_detail_id', $req_detail->id)->get();
            //     $count = 0;
            //     if ($deliveries->count() > 0) {
            //         foreach ($deliveries as $key => $delivery) {
            //             $count += $delivery->quantite;
            //         }
            //     }
            //     if ($req_count === $count) {
            //         $delivered += 1;
            //     }
            // }
        }

        return redirect()->back()->with('success', 'Livraison mise à jour avec succès');
    }
}
