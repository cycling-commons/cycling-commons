<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Form;

use App\Catalog\RouteMetadata;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The one contribution form for a recommended route, in three modes.
 *
 * Default: a first proposal, GPX plus the eight R metadata fields plus rider
 * photos (file content is validated later). With `proposal_edit`: the
 * proposer's own edit while the proposal waits for review, the same fields
 * with the GPX optional (a new file replaces the track) and more photos, the
 * values it showed in `shown`, and the fields a curator has edited
 * (`curator_held`) locked. With
 * `route_photos`: photos and a note for the curator, the mode anyone may use
 * on any live route.
 *
 * Once a curator has decided a proposal its data is not edited here: a rider
 * asks for a change through the drawer's correction box (route-domain.md
 * §7.1) and a curator makes it on the Routes desk. The metadata widgets come
 * from {@see RouteMetadataFields}, the same definitions the desk form builds
 * from.
 *
 * @see docs/specs/route-domain.md §4.1, §4.5, §4.6
 * @see docs/specs/photo-uploads.md §5i
 */
final class ProposeRouteType extends AbstractType
{
    /** @param array<array-key,mixed> $options */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $edit = true === $options['proposal_edit'];
        // Upload ids from media-upload.js, claimed on submit (photo-uploads.md §4, §5i).
        $builder
            ->add('mediaIds', HiddenType::class, ['required' => false])
            ->add('mediaAlts', HiddenType::class, ['required' => false]);
        // The note is the one metadata field the photos-only form also carries.
        RouteMetadataFields::add($builder, ['note'], required: false);
        if (true === $options['route_photos']) {
            return;
        }

        // An edit keeps the stored track unless a new file is chosen.
        $builder->add('gpx', FileType::class, [
            'label' => false,
            'required' => !$edit,
            'attr' => ['accept' => '.gpx'],
            'constraints' => [
                ...($edit ? [] : [new NotBlank(message: 'propose_route.error.gpx_required')]),
                new File(
                    maxSize: '15M',
                    maxSizeMessage: 'propose_route.error.gpx_too_large',
                    extensions: ['gpx' => ['application/gpx+xml', 'application/xml', 'text/xml', 'application/octet-stream', 'text/plain']],
                    extensionsMessage: 'propose_route.error.gpx_type',
                ),
            ],
        ]);
        // All eight registry fields, from the definitions the curator's desk
        // form shares. A first proposal asks for a difficulty and a surface.
        RouteMetadataFields::add(
            $builder,
            [RouteMetadata::NAME_FIELD, 'difficulty', 'season', 'dominantSurface', 'bikeTypes', 'gradientLimited', 'bestDirection'],
            required: true,
        );
        if (!$edit) {
            return;
        }
        // The values the form showed, as JSON, so the save writes only what
        // the proposer changed (RouteProposalService::revise()).
        $builder->add('shown', HiddenType::class, ['required' => false]);
        // The fields it showed locked, as JSON, so a save locks the same ones.
        $builder->add('locked', HiddenType::class, ['required' => false]);
        // A field a curator has edited stays theirs while the proposal waits.
        foreach ($options['curator_held'] as $field) {
            if ($builder->has($field)) {
                $builder->get($field)->setDisabled(true);
            }
        }
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'route_photos' => false,
            'proposal_edit' => false,
            'curator_held' => [],
        ]);
        $resolver->setAllowedTypes('route_photos', 'bool');
        $resolver->setAllowedTypes('proposal_edit', 'bool');
        $resolver->setAllowedTypes('curator_held', 'string[]');
    }
}
