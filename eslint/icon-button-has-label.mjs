// Local rule (PR-UX3, audit R4): an icon-only button must carry an
// accessible name via aria-label / aria-labelledby. `jsx-a11y`'s
// control-has-associated-label treats any child component (e.g. a lucide
// icon) as a possible label, so it cannot catch `<Button><Trash2 /></Button>`.

const BUTTON_NAMES = new Set(['button', 'Button']);

function elementName(node) {
    const name = node.openingElement.name;
    if (name.type === 'JSXIdentifier') return name.name;
    if (name.type === 'JSXMemberExpression') return `${name.object.name}.${name.property.name}`;
    return '';
}

function hasAttr(opening, attrNames) {
    return opening.attributes.some((attr) => {
        if (attr.type === 'JSXSpreadAttribute') return true; // unknown props: assume labelled
        return attrNames.includes(attr.name?.name);
    });
}

export default {
    meta: {
        type: 'problem',
        docs: { description: 'Icon-only buttons need aria-label or aria-labelledby.' },
        schema: [],
        messages: {
            missing: 'Tombol ikon tanpa label: tambahkan aria-label (dan aria-hidden="true" pada ikon).',
        },
    },
    create(context) {
        const iconNames = new Set();

        // Returns 'text' when the subtree may render text, 'icon' when it only
        // renders lucide icons, 'none' when empty.
        function classify(children) {
            let sawIcon = false;
            for (const child of children) {
                if (child.type === 'JSXText') {
                    if (child.value.trim() !== '') return 'text';
                    continue;
                }
                if (child.type === 'JSXExpressionContainer') {
                    if (child.expression.type === 'JSXEmptyExpression') continue;
                    // {cond && <Icon />} / {loading ? <Loader2/> : <Save/>}: inspect JSX branches.
                    const exprs = [child.expression];
                    let onlyIcons = true;
                    while (exprs.length > 0) {
                        const e = exprs.pop();
                        if (e.type === 'LogicalExpression') { exprs.push(e.right); continue; }
                        if (e.type === 'ConditionalExpression') { exprs.push(e.consequent, e.alternate); continue; }
                        if (e.type === 'JSXElement') {
                            const kind = classifyElement(e);
                            if (kind === 'text') { onlyIcons = false; break; }
                            if (kind === 'icon') sawIcon = true;
                            continue;
                        }
                        if (e.type === 'Literal' && (e.value === null || e.value === false || e.value === '')) continue;
                        onlyIcons = false;
                        break;
                    }
                    if (!onlyIcons) return 'text';
                    continue;
                }
                if (child.type === 'JSXElement') {
                    const kind = classifyElement(child);
                    if (kind === 'text') return 'text';
                    if (kind === 'icon') sawIcon = true;
                    continue;
                }
                if (child.type === 'JSXFragment') {
                    const kind = classify(child.children);
                    if (kind === 'text') return 'text';
                    if (kind === 'icon') sawIcon = true;
                }
            }
            return sawIcon ? 'icon' : 'none';
        }

        function classifyElement(el) {
            const name = elementName(el);
            if (iconNames.has(name)) return 'icon';
            if (hasAttr(el.openingElement, ['aria-label', 'aria-labelledby'])) return 'text';
            const kind = classify(el.children);
            // Unknown components without children may render text themselves.
            if (kind === 'none' && /^[A-Z]/.test(name)) return 'text';
            return kind;
        }

        return {
            ImportDeclaration(node) {
                if (node.source.value !== 'lucide-react') return;
                for (const spec of node.specifiers) {
                    if (spec.local?.name) iconNames.add(spec.local.name);
                }
            },
            JSXElement(node) {
                if (!BUTTON_NAMES.has(elementName(node))) return;
                const opening = node.openingElement;
                if (hasAttr(opening, ['aria-label', 'aria-labelledby'])) return;
                if (opening.attributes.some((a) => a.type === 'JSXAttribute' && a.name?.name === 'asChild')) {
                    // <Button asChild><Link>…</Link></Button>: the child carries the name.
                    const inner = node.children.find((c) => c.type === 'JSXElement');
                    if (!inner || hasAttr(inner.openingElement, ['aria-label', 'aria-labelledby'])) return;
                    if (classify(inner.children) === 'icon') context.report({ node: opening, messageId: 'missing' });
                    return;
                }
                if (classify(node.children) === 'icon') {
                    context.report({ node: opening, messageId: 'missing' });
                }
            },
        };
    },
};
