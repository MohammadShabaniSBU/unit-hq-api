<?php

declare(strict_types=1);

return [

    'disclosure' => [
        'en' => 'I am an automated assistant.',
        'es' => 'Soy un asistente automatizado.',
        'fr' => 'Je suis un assistant automatisé.',
    ],

    'pending_approval' => [
        'en' => "I've asked a colleague to confirm that — you'll hear back shortly.",
        'es' => 'He pedido a un colega que lo confirme — te responderemos en breve.',
        'fr' => "J'ai demandé à un collègue de confirmer cela — vous aurez bientôt des nouvelles.",
    ],

    'rules' => [
        'en' => [
            'legal_or_complaint' => [
                'lien',
                'auction',
                'solicitor',
                'lawyer',
                'abogado',
                'ombudsman',
                'court',
                'small claims',
                'chargeback',
                'sue',
                'lawsuit',
                'erasure',
                'right to be forgotten',
                'data protection',
                'gdpr',
                'deceased',
                'estate',
                'probate',
                'insurance claim',
                'damage claim',
                'legal action',
            ],
            'delinquency' => [
                'arrears',
                'overlock',
                'overlocked',
                'cut lock',
                "why can't i get in",
                "can't get in",
                'cannot get in',
                'why cant i get in',
            ],
            'price_negotiation' => [
                'discount',
                'cheaper',
                'cheapest',
                'match',
                'negotiate',
                'negotiation',
                'best you can do',
                'best price',
                'lower the price',
            ],
            'move_out_commitment' => [
                'notice',
                'vacate',
                'move out',
                'terminate',
                'termination',
                'give notice',
                'end my contract',
            ],
            'payment_dispute' => [
                'i already paid',
                'already paid',
                'charged twice',
                'refund',
                'double charged',
                'paid already',
            ],
            'customer_requested' => [
                'human',
                'agent',
                'manager',
                'person',
                'real person',
            ],
            'third_party' => [
                'another tenant',
                'other tenant',
                "someone else's unit",
                "neighbor's unit",
                "neighbour's unit",
                "another unit's",
            ],
        ],
        'es' => [
            'legal_or_complaint' => [
                'embargo',
                'subasta',
                'abogado',
                'abogada',
                'procurador',
                'juzgado',
                'tribunal',
                'demanda',
                'demandar',
                'reclamacion',
                'reclamación',
                'contracargo',
                'chargeback',
                'proteccion de datos',
                'protección de datos',
                'rgpd',
                'derecho al olvido',
                'fallecido',
                'herencia',
                'siniestro',
            ],
            'delinquency' => [
                'mora',
                'atraso',
                'impago',
                'candado',
                'precinto',
                'no puedo entrar',
                'no puedo acceder',
                'me han puesto un candado',
            ],
            'price_negotiation' => [
                'descuento',
                'mas barato',
                'más barato',
                'mas barata',
                'más barata',
                'igualar',
                'negociar',
                'negociacion',
                'negociación',
                'mejor precio',
            ],
            'move_out_commitment' => [
                'preaviso',
                'desalojar',
                'irme',
                'mudarme',
                'terminar',
                'dar de baja',
                'fin de contrato',
            ],
            'payment_dispute' => [
                'ya pague',
                'ya pagué',
                'ya he pagado',
                'cobrado dos veces',
                'reembolso',
                'devolucion',
                'devolución',
            ],
            'customer_requested' => [
                'humano',
                'humana',
                'persona',
                'agente',
                'gerente',
                'persona real',
            ],
            'third_party' => [
                'otro inquilino',
                'otra inquilina',
                'otro cliente',
                'la unidad de al lado',
                'unidad de otro',
            ],
        ],
        'fr' => [
            'legal_or_complaint' => [
                'gage',
                'encheres',
                'enchères',
                'avocat',
                'avocate',
                'tribunal',
                'plainte',
                'poursuivre',
                'rgpd',
                'effacement',
                'succession',
                'deces',
                'décès',
            ],
            'delinquency' => [
                'impaye',
                'impayé',
                'retard de paiement',
                'cadenas',
                'je ne peux pas entrer',
            ],
            'price_negotiation' => [
                'remise',
                'moins cher',
                'negocier',
                'négocier',
                'meilleur prix',
            ],
            'move_out_commitment' => [
                'preavis',
                'préavis',
                'demenager',
                'déménager',
                'resilier',
                'résilier',
            ],
            'payment_dispute' => [
                'deja paye',
                'déjà payé',
                'facture deux fois',
                'remboursement',
            ],
            'customer_requested' => [
                'humain',
                'personne',
                'vrai personne',
                'manager',
                'agent',
            ],
            'third_party' => [
                'un autre locataire',
                "l'unite d'un autre",
                "l'unité d'un autre",
            ],
        ],
    ],

    'forbidden_claims' => [
        'en' => [
            'payment_confirmation' => [
                'payment has been received',
                'payment has been processed',
                'payment has been cleared',
                'we have received your payment',
                'your payment went through',
            ],
            'fee_waiver' => [
                "i've waived",
                'i have waived',
                "we'll cancel the late fee",
                'we will cancel the late fee',
                'no charge for that',
                'fee is waived',
            ],
            'access_grant' => [
                "i've unlocked",
                'i have unlocked',
                'you can get in now',
                "i've removed the overlock",
                'i have removed the overlock',
                'access has been restored',
            ],
            // Every phrase in this group must be true after a committed reservation.
            // The group is licensable by ForbiddenClaimKey::AvailabilityGuarantee, so
            // a phrase that a hold does not make true belongs in a different group.
            'availability_guarantee' => [
                "i've held it for you",
                'i have held it for you',
                "it's reserved",
                'it is reserved',
                'i have reserved',
                "i've reserved",
            ],
            // Licensed by ForbiddenClaimKey::CapacityGuidance on an ok
            // facility.size_guide result. Must catch the trace-30 line
            // ("should work well") plus fit language. Hedging is not a
            // second guard: licensed "should work well" is allowed; "will
            // fit" is a prompt + disclaimer rule.
            'capacity_guidance' => [
                'should work well',
                'will fit',
                'would fit',
                'should fit',
                'can fit',
                'enough space for',
                'plenty of room',
                'big enough',
                'large enough',
            ],
            'legal_advice' => [
                'you are not liable',
                "you're not liable",
                "the contract doesn't allow them to",
                'the contract does not allow them to',
                'you have no legal obligation',
            ],
            'contract_mutation' => [
                "i've updated your contract",
                'i have updated your contract',
                "i've changed your rate",
                'i have changed your rate',
                'your contract has been updated',
            ],
        ],
        'es' => [
            'payment_confirmation' => [
                'hemos recibido su pago',
                'el pago ha sido procesado',
                'el pago ha sido recibido',
            ],
            'fee_waiver' => [
                'he condonado',
                'hemos anulado el recargo',
                'sin cargo por eso',
            ],
            'access_grant' => [
                'ya puede entrar',
                'he quitado el candado',
                'he restaurado el acceso',
            ],
            // Every phrase in this group must be true after a committed reservation.
            // The group is licensable by ForbiddenClaimKey::AvailabilityGuarantee, so
            // a phrase that a hold does not make true belongs in a different group.
            'availability_guarantee' => [
                'lo he reservado',
                'esta reservado',
                'está reservado',
            ],
            // Licensed by ForbiddenClaimKey::CapacityGuidance. Avoid a bare
            // "cabra" (goat); keep the conjugated "le cabrá".
            'capacity_guidance' => [
                'deberia ir bien',
                'debería ir bien',
                'le cabra',
                'le cabrá',
                'le cabria',
                'le cabría',
                'deberia caber',
                'debería caber',
                'espacio suficiente',
                'suficiente espacio',
            ],
            'legal_advice' => [
                'usted no es responsable',
                'el contrato no les permite',
            ],
            'contract_mutation' => [
                'he actualizado su contrato',
                'he cambiado su tarifa',
            ],
        ],
        'fr' => [
            'payment_confirmation' => [
                'nous avons recu votre paiement',
                'nous avons reçu votre paiement',
                'le paiement a ete traite',
                'le paiement a été traité',
            ],
            'fee_waiver' => [
                "j'ai annule les frais",
                "j'ai annulé les frais",
                'pas de frais pour cela',
            ],
            'access_grant' => [
                'vous pouvez entrer maintenant',
                "j'ai retire le cadenas",
                "j'ai retiré le cadenas",
            ],
            // Every phrase in this group must be true after a committed reservation.
            // The group is licensable by ForbiddenClaimKey::AvailabilityGuarantee, so
            // a phrase that a hold does not make true belongs in a different group.
            'availability_guarantee' => [
                "je l'ai reserve",
                "je l'ai réservé",
                "c'est reserve",
                "c'est réservé",
            ],
            // Licensed by ForbiddenClaimKey::CapacityGuidance.
            'capacity_guidance' => [
                'devrait bien convenir',
                'devrait convenir',
                'rentrera',
                'devrait rentrer',
                'assez de place',
                'suffisamment de place',
            ],
            'legal_advice' => [
                "vous n'etes pas responsable",
                "vous n'êtes pas responsable",
                'le contrat ne leur permet pas',
            ],
            'contract_mutation' => [
                "j'ai mis a jour votre contrat",
                "j'ai mis à jour votre contrat",
                "j'ai change votre tarif",
                "j'ai changé votre tarif",
            ],
        ],
    ],

];
