import React, { useEffect, useState } from 'react';
import styled from 'styled-components/macro';
import tw, { TwStyle } from 'twin.macro';
import PageContentBlock from '@/components/elements/PageContentBlock';
import Button from '@/components/elements/Button';
import Input from '@/components/elements/Input';
import Spinner from '@/components/elements/Spinner';
import FlashMessageRender from '@/components/FlashMessageRender';
import { useFlashKey } from '@/plugins/useFlash';
import { ServerContext } from '@/state/server';
import PackCard from '../elements/PackCard';
import InstallModal from '../elements/InstallModal';
import { Source, PackSummary, Installation, Config, getConfig, search as apiSearch, getInstalls, ACTIVE_STATUSES } from '../api';

const FLASH = 'modpackhub';
const PER_PAGE = 20;

const SOURCE_LABEL: Record<Source, string> = { modrinth: 'Modrinth', curseforge: 'CurseForge', url: 'Direct URL' };

const STATUS_LABEL: Record<string, string> = {
    pending: 'queued',
    backing_up: 'backing up…',
    switching_egg: 'preparing…',
    installing: 'installing…',
    restoring_egg: 'restoring egg…',
    completed: 'completed',
    failed: 'failed',
};

const statusTone = (s: string): TwStyle =>
    s === 'completed' ? tw`bg-green-600` : s === 'failed' ? tw`bg-red-600` : tw`bg-yellow-600`;

const Card = styled.div`
    ${tw`flex items-center gap-3 bg-neutral-700 rounded p-3 mb-4 min-w-0 max-w-full`};
    box-sizing: border-box;
`;
const Muted = styled.p`
    ${tw`text-neutral-400 text-center py-5`};
`;
const Badge = styled.span`
    ${tw`text-xs font-semibold text-white rounded-full px-2.5 py-0.5 flex items-center gap-1.5 flex-shrink-0`};
`;
const SectionTitle = styled.div`
    ${tw`text-xs uppercase tracking-wide text-neutral-400 mb-1`};
`;

const ModpacksPage = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { clearFlashes, clearAndAddHttpError } = useFlashKey(FLASH);

    const [config, setConfig] = useState<Config | null>(null);

    const [source, setSource] = useState<Source>('modrinth');
    const [query, setQuery] = useState('');
    const [url, setUrl] = useState('');
    const [results, setResults] = useState<PackSummary[]>([]);
    const [page, setPage] = useState(1);
    const [total, setTotal] = useState(0);
    const [searching, setSearching] = useState(false);

    const [modalPack, setModalPack] = useState<PackSummary | undefined>();
    const [modalUrl, setModalUrl] = useState<string | undefined>();

    const [latest, setLatest] = useState<Installation | null>(null);
    const [backupsAllowed, setBackupsAllowed] = useState(true);
    const [poll, setPoll] = useState(0);

    useEffect(() => {
        getConfig()
            .then((c) => {
                setConfig(c);
                if (c.sources.length > 0) setSource(c.sources[0]);
            })
            .catch((e) => clearAndAddHttpError(e));
    }, []);

    useEffect(() => {
        if (source === 'url') return;
        setSearching(true);
        const t = setTimeout(() => {
            clearFlashes();
            apiSearch(source, query, page)
                .then((r) => {
                    setResults(r.data);
                    setTotal(r.total);
                })
                .catch((e) => {
                    setResults([]);
                    clearAndAddHttpError(e);
                })
                .finally(() => setSearching(false));
        }, 300);
        return () => clearTimeout(t);
    }, [source, query, page]);

    useEffect(() => {
        let alive = true;
        let timer: ReturnType<typeof setTimeout>;

        const tick = () => {
            getInstalls(uuid)
                .then((res) => {
                    if (!alive) return;
                    const row = res.current ?? null;
                    setLatest(row);
                    setBackupsAllowed(res.backups_allowed);

                    const active = !!row && ACTIVE_STATUSES.includes(row.status);
                    if (active || !document.hidden) {
                        timer = setTimeout(tick, active ? 3000 : 30000);
                    }
                })
                .catch((e) => alive && clearAndAddHttpError(e));
        };
        tick();

        const onVisible = () => {
            if (!document.hidden) {
                clearTimeout(timer);
                tick();
            }
        };
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            alive = false;
            clearTimeout(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [uuid, poll]);

    const onSourceChange = (s: Source) => {
        setSource(s);
        setPage(1);
        setResults([]);
    };

    const afterStarted = () => {
        setModalPack(undefined);
        setModalUrl(undefined);
        clearFlashes();
        setPoll((p) => p + 1);
    };

    const lastPage = Math.max(1, Math.ceil(total / PER_PAGE));

    return (
        <PageContentBlock title={'Modpacks'}>
            <div css={tw`mb-4`}>
                <FlashMessageRender byKey={FLASH} />
            </div>

            {latest && (
                <div>
                    <SectionTitle>Last installation</SectionTitle>
                    <Card>
                        <div css={tw`flex-1 min-w-0`}>
                            <div css={tw`font-semibold text-neutral-100`}>{latest.pack_name}</div>
                            <div css={tw`text-xs text-neutral-400`}>
                                {SOURCE_LABEL[latest.source]} {latest.mc_version ? `· MC ${latest.mc_version}` : ''}{' '}
                                {latest.loader && latest.loader !== 'none' ? `· ${latest.loader}` : ''}
                            </div>
                            {latest.error_message && <div css={tw`text-xs text-red-300 mt-0.5`}>{latest.error_message}</div>}
                        </div>
                        <Badge css={statusTone(latest.status)}>
                            {ACTIVE_STATUSES.includes(latest.status) && <Spinner size={'small'} />}
                            {STATUS_LABEL[latest.status] ?? latest.status}
                        </Badge>
                    </Card>
                </div>
            )}

            <SectionTitle>Install a modpack</SectionTitle>
            <div css={tw`flex gap-2 mb-3 flex-wrap`}>
                {(config?.sources ?? []).map((s) => (
                    <Button key={s} size={'small'} isSecondary={source !== s} onClick={() => onSourceChange(s)}>
                        {SOURCE_LABEL[s]}
                    </Button>
                ))}
            </div>

            {source === 'url' ? (
                <div css={tw`flex gap-2 mb-4`}>
                    <Input
                        type={'text'}
                        placeholder={'https://…/modpack.zip or .mrpack'}
                        value={url}
                        onChange={(e) => setUrl(e.currentTarget.value)}
                    />
                    <Button disabled={!url.trim()} onClick={() => setModalUrl(url.trim())}>
                        Continue
                    </Button>
                </div>
            ) : (
                <div css={tw`mb-4`}>
                    <Input
                        type={'text'}
                        placeholder={`Search a modpack on ${SOURCE_LABEL[source]}…`}
                        value={query}
                        onChange={(e) => {
                            setPage(1);
                            setQuery(e.currentTarget.value);
                        }}
                    />
                </div>
            )}

            {source !== 'url' && (
                <>
                    {searching && (
                        <div css={tw`p-5 text-center`}>
                            <Spinner size={'large'} centered />
                        </div>
                    )}
                    {!searching && results.length === 0 && (
                        <Muted>{query ? 'No results.' : 'Type to search for a modpack.'}</Muted>
                    )}
                    <div css={tw`grid gap-2`} style={{ gridTemplateColumns: 'minmax(0, 1fr)' }}>
                        {results.map((p) => (
                            <PackCard key={`${p.source}-${p.id}`} pack={p} onInstall={setModalPack} />
                        ))}
                    </div>
                    {total > PER_PAGE && (
                        <div css={tw`flex justify-center items-center gap-3 mt-4`}>
                            <Button size={'small'} isSecondary disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                                ‹ Previous
                            </Button>
                            <span css={tw`text-sm text-neutral-400`}>
                                page {page} / {lastPage}
                            </span>
                            <Button size={'small'} isSecondary disabled={page >= lastPage} onClick={() => setPage((p) => p + 1)}>
                                Next ›
                            </Button>
                        </div>
                    )}
                </>
            )}

            {(modalPack || modalUrl) && (
                <InstallModal
                    serverUuid={uuid}
                    pack={modalPack}
                    url={modalUrl}
                    backupsAllowed={backupsAllowed}
                    onClose={() => {
                        setModalPack(undefined);
                        setModalUrl(undefined);
                    }}
                    onStarted={afterStarted}
                    onError={(e) => {
                        setModalPack(undefined);
                        setModalUrl(undefined);
                        clearAndAddHttpError(e);
                    }}
                />
            )}
        </PageContentBlock>
    );
};

export default ModpacksPage;
