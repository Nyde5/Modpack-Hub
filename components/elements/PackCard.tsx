import React from 'react';
import styled from 'styled-components/macro';
import tw from 'twin.macro';
import Button from '@/components/elements/Button';
import { PackSummary } from '../api';

const Card = styled.div`
    ${tw`flex items-center gap-3 bg-neutral-700 rounded p-3 min-w-0 max-w-full`};
    box-sizing: border-box;
`;
const Icon = styled.img`
    ${tw`w-12 h-12 rounded object-cover bg-neutral-800 flex-shrink-0`};
`;
const Name = styled.div`
    ${tw`font-semibold text-neutral-100 truncate`};
`;
const Summary = styled.div`
    ${tw`text-xs text-neutral-400 truncate`};
`;
const Meta = styled.div`
    ${tw`text-xs text-neutral-500 mt-1`};
`;

const fmt = (n: number): string =>
    n >= 1_000_000 ? `${(n / 1_000_000).toFixed(1)}M` : n >= 1000 ? `${(n / 1000).toFixed(1)}k` : `${n}`;

export default ({ pack, onInstall }: { pack: PackSummary; onInstall: (pack: PackSummary) => void }) => (
    <Card>
        <Icon
            src={pack.icon_url || ''}
            alt={''}
            onError={(e) => {
                (e.target as HTMLImageElement).style.visibility = 'hidden';
            }}
        />
        <div css={tw`flex-1 min-w-0`}>
            <Name>{pack.name}</Name>
            <Summary>{pack.summary}</Summary>
            <Meta>
                {fmt(pack.downloads)} downloads
                {pack.distributable === false && ' · manual download only'}
            </Meta>
        </div>
        <Button size={'small'} css={tw`flex-shrink-0`} onClick={() => onInstall(pack)}>
            Install
        </Button>
    </Card>
);
